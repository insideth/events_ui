<?php

use Events\API\Util;

$calendars = elgg_extract('calendars', $vars);
if (empty($calendars)) {
	return;
}

$now = time();
$start = (int) Util::getMonthStart((int) elgg_extract('start_time', $vars, $now));
$end = (int) Util::getMonthEnd($start);

if ($start < $now && elgg_extract('upcoming', $vars, true)) {
	// don't show anything that's passed
	if ($end >= $now) {
		// looking at the current month
		$start = $now;
	} else {
		// this is an invalid date for these settings
		echo elgg_echo('events:widgets:noresults');
		return;
	}
}

$timezone = Util::getClientTimezone();
$start_local = $start - Util::getOffset($start, Util::UTC, $timezone);
$end_local = $end - Util::getOffset($end, Util::UTC, $timezone);

$events = array();
foreach ($calendars as $c) {
	$cevents = $c->getAllEventInstances($start_local, $end_local);
	$events = array_merge($events, $cevents);
}

// need to weed out duplicates
// since some events can show up on multiple calendars
// $events = array_map('unserialize', array_unique(array_map('serialize', $events))); // calendar param on urls makes this not work :(
$dupes = array();
foreach ($events as $key => $instance) {
	$test = $instance['guid'] . ':' . $instance['start_timestamp'];

	if (in_array($test, $dupes)) {
		unset($events[$key]);
		continue;
	}

	$dupes[] = $test;
}

// also re-order by time
elgg_sort_3d_array_by_value($events, 'start_timestamp', SORT_ASC, SORT_NUMERIC);

// finally limit the number of results
$limit = elgg_extract('limit', $vars, elgg_get_config('default_limit'));
if ($limit && count($events) > $limit) {
	$events = array_slice($events, 0, $limit);
}

if (empty($events)) {
	echo elgg_echo('events:widgets:noresults');
	return;
}

echo elgg_view('events_ui/feed', array(
	'events' => $events
));
