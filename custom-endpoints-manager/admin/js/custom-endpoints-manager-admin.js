/**
 * Admin JS for Custom Endpoints Manager.
 *
 * @package Custom_Endpoints_Manager
 */

(function ( $ ) {
	'use strict';

	$( document ).ready(
		function () {

			// Build microplugin <select> options from localized data.
			function buildMicropluginSelect( name ) {
				var options = '<option value="">' + cemI18n.selectMicroplugin + '</option>';
				if ( typeof cemMicropluginOptions !== 'undefined' ) {
					$.each(
						cemMicropluginOptions,
						function ( i, mp ) {
							options += '<option value="' + mp.id + '">' + $( '<div>' ).text( mp.title ).html() + '</option>';
						}
					);
				}
				return '<select name="' + name + '">' + options + '</select>';
			}

			// Add new endpoint row.
			$( '#add-endpoint' ).on(
				'click',
				function () {
					var index  = $( '#custom-endpoints-list tr' ).length;
					var newRow =
					'<tr>' +
					'<td><input type="text" name="cem_endpoints[' + index + '][slug]" value="" class="regular-text" /></td>' +
					'<td><input type="text" name="cem_endpoints[' + index + '][methods]" value="" class="small-text" placeholder="GET" /></td>' +
					'<td><input type="text" name="cem_endpoints[' + index + '][capability]" value="" class="regular-text" placeholder="read" /></td>' +
					'<td>' + buildMicropluginSelect( 'cem_endpoints[' + index + '][microplugin_id]' ) + '</td>' +
					'<td><input type="text" name="cem_endpoints[' + index + '][args]" value="" class="regular-text" placeholder="id:integer,s:string" /></td>' +
					'<td style="white-space:nowrap"><label><input type="checkbox" name="cem_endpoints[' + index + '][async]" value="1" /> Async</label><br><label style="font-size:11px">Attempts: <input type="number" name="cem_endpoints[' + index + '][max_attempts]" value="3" min="1" max="10" style="width:45px" /></label></td>' +
					'<td><button type="button" class="button button-secondary remove-endpoint">' + cemI18n.remove + '</button></td>' +
					'</tr>';

					$( '#custom-endpoints-list' ).append( newRow );
				}
			);

			// Remove endpoint row.
			$( '#custom-endpoints-list' ).on(
				'click',
				'.remove-endpoint',
				function () {
					$( this ).closest( 'tr' ).remove();
				}
			);
		}
	);

})( jQuery );
