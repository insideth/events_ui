<?php

namespace Events\UI;

use ElggBatch;
use ElggGroup;
use ElggUser;
use Events\API\Calendar;
use Events\API\Event;
use Events\API\Util;
use DateTime;
use DateTimeZone;

/**
 * Register title menu items for an event
 *
 * @param Event $event
 * @return void
 */
function register_event_title_menu($event, $ts = null, $calendar = null) {

	if (!$event instanceof Event) {
		return;
	}

	$params = array(
		'event' => $event,
		'timestamp' => $ts,
		'calendar' => $calendar,
	);

	$profile_buttons = elgg_trigger_plugin_hook('profile_buttons', 'object:event', $params, array());

	foreach ($profile_buttons as $button) {
		elgg_register_menu_item('title', $button);
	}
}

/**
 * Adds group events to the default calendar of interested members
 * 
 * @param int $event_guid GUID of the event
 * @param int $group_guid GUID of the group
 * @return void
 */
function autosync_group_event($event_guid, $group_guid) {
	$ia = elgg_set_ignore_access(true);
	// note that this function can be called after shutdown with vroom
	// using guids for params so that we're not performing operations on potentially stale entities
	$event = get_entity($event_guid);
	$group = get_entity($group_guid);

	if (!($event instanceof Event) || !($group instanceof ElggGroup)) {
		return false;
	}

	// get group members
	$options = array(
		'type' => 'user',
		'relationship' => 'member',
		'relationship_guid' => $group->guid,
		'inverse_relationship' => true,
		'limit' => false
	);

	$users = new ElggBatch('elgg_get_entities_from_relationship', $options);
	foreach ($users as $u) {
		// only add to the calendar if they have not opted out
		if (!check_entity_relationship($u->guid, 'calendar_nosync', $group->guid)) {
			// they have not opted out, we should add it to their calendars
			$calendar = Calendar::getPublicCalendar($u);
			$calendar->addEvent($event);
		}
	}

	elgg_set_ignore_access($ia);
}

/**
 * Registered a deferred function
 *
 * @param string $function the name of the function to be called
 * @param array  $args     an array of arguments to pass to the function
 * @param bool   $runonce  limit the function to only running once with a set of arguments
 * @return void
 */
function register_vroom_function($function, $args = array(), $runonce = true) {
	$vroom_functions = elgg_get_config('event_vroom_functions');

	if (!is_array($vroom_functions)) {
		$vroom_functions = array();
	}

	if ($runonce) {
		foreach ($vroom_functions as $array) {
			foreach ($array as $f => $a) {
				if ($f === $function && $a === $args) {
					return true; // this function is already registered with these args
				}
			}
		}
	}

	$vroom_functions[] = array($function => $args);

	elgg_set_config('event_vroom_functions', $vroom_functions);
}

/**
 * Returns preferred calendar notifications methods for the user
 *
 * @param ElggUser $user              User
 * @param string   $notification_name Notification name
 * @return type
 */
function get_calendar_notification_methods($user, $notification_name) {

	if (!($user instanceof ElggUser)) {
		return array();
	}

	$methods = array();
	$NOTIFICATION_HANDLERS = _elgg_services()->notifications->getMethods();
	foreach ($NOTIFICATION_HANDLERS as $method => $foo) {
		$attr = '__notify_' . $method . '_' . $notification_name;

		// default to on if not set
		if (!isset($user->$attr) || $user->$attr) {
			$methods[] = $method;
		}
	}

	return $methods;
}

/**
 * Returns calendar  notification types
 * @return string[]
 */
function get_calendar_notifications() {
	$calendar_notifications = array(
		'addtocal',
		'eventupdate',
		'eventreminder'
	);

	return $calendar_notifications;
}

/**
 * Send notifications about event updates to those users that have added the event
 * to their calendar
 * 
 * @param int $event_guid GUID of the event
 * @return void
 */
function event_update_notify($event_guid) {
	$ia = elgg_set_ignore_access(true);
	$event = get_entity($event_guid);

	if (!($event instanceof Event)) {
		return false;
	}

	$dbprefix = elgg_get_config('dbprefix');
	$options = array(
		'type' => 'object',
		'subtype' => 'calendar',
		'relationship' => Calendar::EVENT_CALENDAR_RELATIONSHIP,
		'relationship_guid' => $event->guid,
		'joins' => array(
			// limit the results to calendars contained by users
			"JOIN {$dbprefix}users_entity ue ON e.container_guid = ue.guid"
		),
		'limit' => false
	);

	$calendars = new ElggBatch('elgg_get_entities_from_relationship', $options);
	
	$owner = $event->getOwnerEntity();
	$owner_link = elgg_view('output/url', array(
		'text' => $owner->name,
		'href' => $owner->getURL()
	));
	
	$in_group = '';
	$in_group_link = '';
	$container = $event->getContainerEntity();
	$container_link = elgg_view('output/url', array(
		'text' => $container->name,
		'href' => $container->getURL()
	));
	if ($container instanceof \ElggGroup) {
		$in_group = elgg_echo('events:notify:subject:ingroup', array($container->name));
		$in_group_link = elgg_echo('events:notify:subject:ingroup', array($container_link));
	}
	
	$event_link = elgg_view('output/url', array(
		'text' => $event->title,
		'href' => $event->getURL()
	));

	$notified = array(); // users could have multiple calendars
	foreach ($calendars as $c) {
		$user = $c->getContainerEntity();

		if (in_array($user->guid, $notified)) {
			continue;
		}

		$ia = elgg_set_ignore_access(false);
		if (!has_access_to_entity($event, $user)) {
			// the user can't see it, lets not notify them
			$notified[] = $user->guid;
			elgg_set_ignore_access($ia);
			continue;
		}
		elgg_set_ignore_access($ia);

		$notify_self = false;
		// support for notify self
		if (is_callable('notify_self_should_notify')) {
			$notify_self = notify_self_should_notify($user);
		}

		if (elgg_get_logged_in_user_guid() == $user->guid && !$notify_self) {
			$notified[] = $user->guid;
			continue;
		}

		$methods = get_calendar_notification_methods($user, 'eventupdate');
		if (!$methods) {
			$notified[] = $user->guid;
			continue;
		}
		
		$starttimestamp = $event->getNextOccurrence();
		$endtimestamp = $starttimestamp + $event->delta;
		
		$timezone = Util::getClientTimezone($user);

		$subject = elgg_echo('event:notify:eventupdate:subject', array(
			html_entity_decode($event->title),
			$in_group,
			$owner->name
		));
		

		$message = elgg_echo('event:notify:eventupdate:message', array(
			$owner_link,
			$event_link,
			$in_group_link,
			elgg_view('output/events_ui/date_range', array('start' => $event->getStartTimestamp(), 'end' => $event->getEndTimestamp(), 'timezone' => $timezone)),
			$event->location,
			$event->description,
		));

		$params = array(
			'event' => $event,
			'entity' => $event, // for BC with internal Arck message parsing plugins
			'calendar' => $c,
			'user' => $user
		);
		$subject = elgg_trigger_plugin_hook('events_ui', 'subject:eventupdate', $params, $subject);
		$message = elgg_trigger_plugin_hook('events_ui', 'message:eventupdate', $params, $message);
		
		$params = array();
		if ($event->canComment($user->guid)) {
			$params = array('entity' => $event);
		}
		notify_user(
				$user->guid,
				$event->container_guid, // user or group
				$subject,
				$message,
				$params,
				$methods
		);

		$notified[] = $user->guid;
	}

	elgg_set_ignore_access($ia);
}

/**
 * Send reminder notifications to users based on their notification settings
 * @todo if there are a *lot* of recipients we should somehow break this off into parallel threads
 * 
 * @param Event $event Event
 * @return void
 */
function send_event_reminder($event, $remindertime = null) {

	$force_send = true;
	if ($remindertime === null) {
		$remindertime = time();
		$force_send = false; // default cron send
	}

	$dbprefix = elgg_get_config('dbprefix');
	$options = array(
		'type' => 'object',
		'subtype' => 'calendar',
		'relationship' => Calendar::EVENT_CALENDAR_RELATIONSHIP,
		'relationship_guid' => $event->guid,
		'joins' => array(
			// limit the results to calendars contained by users
			"JOIN {$dbprefix}users_entity ue ON e.container_guid = ue.guid"
		),
		'limit' => false
	);

	$calendars = new ElggBatch('elgg_get_entities_from_relationship', $options);

	$starttimestamp = $event->getNextOccurrence($remindertime);
	$endtimestamp = $starttimestamp + $event->end_delta;

	// prevent sending if it was in the past, unless this is a forced reminder
	if (!$force_send && $starttimestamp < strtotime('-10 minutes')) {
		return true;
	}
	
	$owner = $event->getOwnerEntity();
	$owner_link = elgg_view('output/url', array(
		'text' => $owner->name,
		'href' => $owner->getURL()
	));
	
	$in_group = '';
	$in_group_link = '';
	$container = $event->getContainerEntity();
	$container_link = elgg_view('output/url', array(
		'text' => $container->name,
		'href' => $container->getURL()
	));
	if ($container instanceof \ElggGroup) {
		$in_group = elgg_echo('events:notify:subject:ingroup', array($container->name));
		$in_group_link = elgg_echo('events:notify:subject:ingroup', array($container_link));
	}
	
	$event_link = elgg_view('output/url', array(
		'text' => $event->title,
		'href' => $event->getURL()
	));

	$notified = array(); // users could have multiple calendars
	foreach ($calendars as $calendar) {
		$user = $calendar->getContainerEntity();

		if (in_array($user->guid, $notified)) {
			continue;
		}

		$ia = elgg_set_ignore_access(false);
		if (!has_access_to_entity($event, $user)) { error_log($user->username . ' does not have access to ' . $event->guid);
			// the user can't see it, lets not notify them
			$notified[] = $user->guid;
			elgg_set_ignore_access($ia);
			continue;
		}
		elgg_set_ignore_access($ia);

		$notify_self = false;
		// support for notify self
		if (is_callable('notify_self_should_notify')) {
			$notify_self = notify_self_should_notify($user);
		}

		if (elgg_get_logged_in_user_guid() == $user->guid && !$notify_self) {
			$notified[] = $user->guid;
			continue;
		}

		$methods = get_calendar_notification_methods($user, 'eventreminder');
		if (!$methods) {
			$notified[] = $user->guid;
			continue;
		}

		$timezone = Util::getClientTimezone($user);
		$dt = new DateTime(null, new DateTimeZone($timezone));
		$dt->modify("$event->start_date $event->start_time");

		$original_subject = elgg_echo('event:notify:eventreminder:subject', array(
			$event->title,
			$in_group,
			$dt->format('D, F j g:ia T')
		));

		$original_message = elgg_echo('event:notify:eventreminder:message', array(
			$event_link,
			$in_group_link,
			elgg_view('output/events_ui/date_range', array('start' => $starttimestamp, 'end' => $endtimestamp, 'timezone' => $timezone)),
			$event->location,
			$event->description,
		));

		$params = array(
			'event' => $event,
			'entity' => $event, // for back compatibility with some internal Arck message parsing plugins
			'calendar' => $calendar,
			'user' => $user,
			'starttime' => $starttimestamp,
			'endtime' => $endtimestamp
		);
		$subject = elgg_trigger_plugin_hook('events_ui', 'subject:eventreminder', $params, $original_subject);
		$message = elgg_trigger_plugin_hook('events_ui', 'message:eventreminder', $params, $original_message);

		notify_user(
				$user->guid, $event->container_guid, // user or group
				$subject, $message, array(), $methods
		);

		$notified[] = $user->guid;
	}
}
