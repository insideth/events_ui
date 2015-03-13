<?php

namespace Events\UI;
use Events\API\Util;

$start = (int) Util::getMonthStart((int) get_input('event_widget_start', time()));

$prev_start = strtotime('-1 month', $start);
$next_start = strtotime('+1 month', $start);

$prev = elgg_view('output/url', array(
	'text' => '<<',
	'href' => '#',
	'class' => 'elgg-button elgg-button-action events-widget-nav',
	'data-guid' => $vars['entity']->guid,
	'data-start' => $prev_start
));


if ($prev_start < time() && $start < time() && $vars['entity']->upcoming) {
	$prev = '&nbsp;';
}

$next = elgg_view('output/url', array(
	'text' => '>>',
	'href' => '#',
	'class' => 'elgg-button elgg-button-action events-widget-nav float-alt',
	'data-guid' => $vars['entity']->guid,
	'data-start' => $next_start
));


$current = date('F', $start);

?>
<div class="row clearfix mbm">
	<div class="elgg-col elgg-col-1of3">
		<?php echo $prev; ?>
	</div>
	<div class="elgg-col elgg-col-1of3 center pts">
		<h3><?php echo $current; ?></h3>
	</div>
	<div class="elgg-col elgg-col-1of3">
		<?php echo $next; ?>
	</div>
</div>


<script>
	// inline js, ugh
	$(document).ready(function() {
		$('#elgg-widget-<?php echo $vars['entity']->guid; ?> .events-widget-nav').click(function(e) {
			e.preventDefault();
			var guid = $(this).data('guid');
			var start = $(this).data('start');
			
			elgg.get('ajax/view/widgets/events/content', {
				data: {
					guid: guid,
					event_widget_start: start
				},
				beforeSend: function() {
					$('#elgg-widget-content-<?php echo $vars['entity']->guid; ?>').html('<div class="elgg-ajax-loader"></div>');
				},
				success: function(result) {
					$('#elgg-widget-content-<?php echo $vars['entity']->guid; ?>').html(result);
				}
			});
		});
	});
</script>