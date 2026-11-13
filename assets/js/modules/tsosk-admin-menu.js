/**
 * TSO Swiss Knife – Admin JavaScript: admin-menu tab.
 *
 * Split from tsosk-admin.js; loaded only on the tab(s) that render this module's markup.
 * Shared helpers come from the core script through window.tsoskAdmin.
 *
 * @package TSO_Swiss_Knife
 */

/* global tsosk, jQuery */
( function ( $ ) {
	'use strict';

	var tsoskCore = window.tsoskAdmin;
	var ajaxPost = tsoskCore.ajaxPost;
	var showMsg = tsoskCore.showMsg;


	// ── Admin Menu Editor ─────────────────────────────────────────────────────

	function tsoskAmGetTopSlug( $topRow ) {
		return String( $topRow.attr( 'data-slug' ) || '' );
	}

	var tsoskAmExpandedSections = {};

	function tsoskAmSectionSlug( $row ) {
		return String( $row.attr( 'data-am-section' ) || $row.attr( 'data-slug' ) || '' );
	}

	function tsoskAmTopRowBySection( sectionSlug ) {
		return $( '#tsosk-am-tbody tr.tsosk-am-row-top' ).filter( function () {
			return tsoskAmSectionSlug( $( this ) ) === sectionSlug;
		} );
	}

	function tsoskAmSubRowsBySection( sectionSlug ) {
		return $( '#tsosk-am-tbody tr.tsosk-am-row-sub, #tsosk-am-tbody tr.tsosk-am-row-nested-top' ).filter( function () {
			return tsoskAmGetSubParentSlug( $( this ) ) === sectionSlug;
		} );
	}

	function tsoskAmSetSectionExpanded( sectionSlug, expanded ) {
		if ( ! sectionSlug ) {
			return;
		}
		if ( expanded ) {
			tsoskAmExpandedSections[ sectionSlug ] = true;
		} else {
			delete tsoskAmExpandedSections[ sectionSlug ];
		}

		var $top = tsoskAmTopRowBySection( sectionSlug );
		$top.toggleClass( 'is-am-expanded', expanded );

		var $toggle = $top.find( '.tsosk-am-toggle' );
		if ( $toggle.length ) {
			$toggle.attr( 'aria-expanded', expanded ? 'true' : 'false' );
			$toggle.find( '.dashicons' )
				.toggleClass( 'dashicons-arrow-right-alt2', ! expanded )
				.toggleClass( 'dashicons-arrow-down-alt2', expanded );
		}

		tsoskAmSubRowsBySection( sectionSlug ).each( function () {
			var $sub = $( this );
			$sub.toggleClass( 'is-am-collapsed', ! expanded );
			$sub.prop( 'hidden', ! expanded );
		} );

		if ( $.fn.sortable && $( '#tsosk-am-tbody' ).hasClass( 'ui-sortable' ) ) {
			$( '#tsosk-am-tbody' ).sortable( 'refresh' );
		}
	}

	function tsoskAmApplyCollapseState() {
		$( '#tsosk-am-tbody tr.tsosk-am-row-top.tsosk-am-has-children' ).each( function () {
			var slug = tsoskAmSectionSlug( $( this ) );
			tsoskAmSetSectionExpanded( slug, !! tsoskAmExpandedSections[ slug ] );
		} );
	}

	function tsoskAmExpandAllSections( expand ) {
		$( '#tsosk-am-tbody tr.tsosk-am-row-top.tsosk-am-has-children' ).each( function () {
			tsoskAmSetSectionExpanded( tsoskAmSectionSlug( $( this ) ), expand );
		} );
	}

	function tsoskAmToggleSection( sectionSlug ) {
		tsoskAmSetSectionExpanded( sectionSlug, ! tsoskAmExpandedSections[ sectionSlug ] );
	}

	function tsoskAmGetSubParentSlug( $row ) {
		var nestUnder = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
		if ( nestUnder && ( $row.hasClass( 'tsosk-am-row-nested-top' ) || $row.hasClass( 'tsosk-am-row-top' ) ) ) {
			return nestUnder;
		}
		return $row.find( '.tsosk-am-parent' ).val() || $row.attr( 'data-am-parent-section' ) || '';
	}

	/**
	 * Nearest section above a dropped row. Separators are never containers, and
	 * hidden children of collapsed sections are skipped by looking at top rows only.
	 */
	function tsoskAmSectionAbove( $dragged ) {
		return $dragged.prevAll( 'tr.tsosk-am-row-top' ).filter( function () {
			return ! /^separator/.test( tsoskAmGetTopSlug( $( this ) ) );
		} ).first();
	}

	/**
	 * Update the parent of a dropped row and return the section to expand (if any).
	 *
	 * A plain top-level item never changes parent by dragging: it only changes position.
	 * Moving a menu inside another section is done explicitly with the
	 * "Top-level section" selector, so dropping next to a collapsed section's
	 * hidden children can no longer nest it by accident.
	 *
	 * @param {jQuery} $dragged Dropped row.
	 * @return {string} Section slug to keep open.
	 */
	function tsoskAmAssignParentFromDrag( $dragged ) {
		if ( ! $dragged || ! $dragged.length ) {
			return '';
		}

		if ( $dragged.hasClass( 'tsosk-am-row-nested-top' ) ) {
			var $nestTop = tsoskAmSectionAbove( $dragged );
			if ( $nestTop.length ) {
				var nestSlug = tsoskAmGetTopSlug( $nestTop );
				$dragged.find( '.tsosk-am-top-nest' ).val( nestSlug );
				$dragged.attr( 'data-am-parent-section', nestSlug );
				return nestSlug;
			}
			return '';
		}

		if ( $dragged.hasClass( 'tsosk-am-row-sub' ) ) {
			var $subTop = tsoskAmSectionAbove( $dragged );
			if ( $subTop.length ) {
				var subSlug = tsoskAmGetTopSlug( $subTop );
				$dragged.find( '.tsosk-am-parent' ).val( subSlug );
				$dragged.attr( 'data-am-parent-section', subSlug );
				return subSlug;
			}
		}

		return '';
	}

	function tsoskAmNormalizeRows( expandSection ) {
		var $tbody = $( '#tsosk-am-tbody' );
		if ( ! $tbody.length ) {
			return;
		}

		var rows = [];
		$tbody.find( 'tr.tsosk-am-row' ).each( function () {
			rows.push( $( this ).detach() );
		} );

		// Nested plugin menus restored to a top-level section.
		rows.forEach( function ( $row ) {
			if ( ! $row.hasClass( 'tsosk-am-row-nested-top' ) ) {
				return;
			}
			var nestUnder = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
			if ( nestUnder ) {
				return;
			}
			var topSlug = tsoskAmGetTopSlug( $row );
			$row.removeClass( 'tsosk-am-row-sub tsosk-am-row-nested-top' )
				.addClass( 'tsosk-am-row-top' )
				.attr( 'data-am-section', topSlug )
				.removeAttr( 'data-am-parent-section' );
		} );

		// Top-level plugin menus configured to live under another section.
		rows.forEach( function ( $row ) {
			if ( ! $row.hasClass( 'tsosk-am-row-top' ) || $row.hasClass( 'tsosk-am-row-nested-top' ) ) {
				return;
			}
			var topSlug   = tsoskAmGetTopSlug( $row );
			var nestUnder = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
			if ( ! nestUnder || nestUnder === topSlug ) {
				return;
			}
			$row.removeClass( 'tsosk-am-row-top' )
				.addClass( 'tsosk-am-row-sub tsosk-am-row-nested-top' )
				.attr( 'data-am-parent-section', nestUnder )
				.removeAttr( 'data-am-section' );
		} );

		var topRows          = [];
		var childrenByParent = {};

		rows.forEach( function ( $row ) {
			if ( $row.hasClass( 'tsosk-am-row-top' ) ) {
				topRows.push( $row );
				return;
			}
			var parent = tsoskAmGetSubParentSlug( $row );
			if ( ! parent ) {
				return;
			}
			if ( ! childrenByParent[ parent ] ) {
				childrenByParent[ parent ] = [];
			}
			childrenByParent[ parent ].push( $row );
		} );

		topRows.forEach( function ( $top ) {
			var slug = tsoskAmGetTopSlug( $top );
			$top.addClass( 'tsosk-am-row-top' ).removeClass( 'tsosk-am-row-sub tsosk-am-row-nested-top' );
			$top.attr( 'data-am-section', slug ).removeAttr( 'data-am-parent-section' );
			$tbody.append( $top );

			var kids = childrenByParent[ slug ] || [];
			kids.forEach( function ( $child ) {
				$tbody.append( $child );
			} );
		} );

		rows.forEach( function ( $row ) {
			if ( $row.hasClass( 'tsosk-am-row-top' ) ) {
				return;
			}
			var parent = tsoskAmGetSubParentSlug( $row );
			if ( parent && childrenByParent[ parent ] ) {
				var parentIsTop = topRows.some( function ( $top ) {
					return tsoskAmGetTopSlug( $top ) === parent;
				} );
				if ( parentIsTop ) {
					return;
				}
			}
			$tbody.append( $row );
		} );

		if ( expandSection ) {
			tsoskAmSetSectionExpanded( expandSection, true );
		} else {
			tsoskAmApplyCollapseState();
		}

		if ( $.fn.sortable && $tbody.hasClass( 'ui-sortable' ) ) {
			$tbody.sortable( 'refresh' );
		}
	}

	function tsoskAmCollectItems() {
		var items = [];
		var sort  = 0;

		function pushRow( $row ) {
			var isTopRow = $row.hasClass( 'tsosk-am-row-top' ) && ! $row.hasClass( 'tsosk-am-row-nested-top' );
			var item     = {
				id     : $row.attr( 'data-id' ),
				slug   : $.trim( $row.attr( 'data-slug' ) || '' ),
				visible: ! $row.find( '.tsosk-am-hide' ).is( ':checked' ),
				label  : $.trim( $row.find( '.tsosk-am-label' ).val() ),
				sort   : sort
			};

			if ( ! isTopRow ) {
				item.effective_parent = tsoskAmGetSubParentSlug( $row );
				item.parent_slug      = $row.find( '.tsosk-am-parent' ).val() || item.effective_parent || '';
				item.orig_parent        = $row.attr( 'data-orig-parent' ) || '';
			}
			if ( $row.hasClass( 'tsosk-am-row-top' ) || $row.hasClass( 'tsosk-am-row-nested-top' ) ) {
				item.nest_under = $.trim( $row.find( '.tsosk-am-top-nest' ).val() || '' );
			}

			items.push( item );
			sort += 1;
		}

		// Preserve exact on-screen order from the main list first.
		$( '#tsosk-am-tbody > tr.tsosk-am-row' ).each( function () {
			pushRow( $( this ) );
		} );
		$( '#tsosk-am-hidden-tbody > tr.tsosk-am-row' ).each( function () {
			pushRow( $( this ) );
		} );

		return items;
	}

	/**
	 * Build top/sub menu order maps from the current table layout (visual order).
	 *
	 * @return {{top_order: string[], sub_order: Object.<string, string[]>}}
	 */
	function tsoskAmBuildSectionOrders() {
		var topOrder    = [];
		var subOrder    = {};
		var subOrderIds = {};
		var currentSection = '';

		// Read exact tbody DOM order (do not regroup — preserves drag order).
		$( '#tsosk-am-tbody > tr.tsosk-am-row' ).each( function () {
			var $row   = $( this );
			var slug   = $.trim( $row.attr( 'data-slug' ) || '' );
			var id     = $.trim( $row.attr( 'data-id' ) || '' );
			var isTop  = $row.hasClass( 'tsosk-am-row-top' ) && ! $row.hasClass( 'tsosk-am-row-nested-top' );

			if ( isTop ) {
				if ( slug && topOrder.indexOf( slug ) === -1 ) {
					topOrder.push( slug );
				}
				currentSection = slug;
				return;
			}

			if ( ! slug || ! id ) {
				return;
			}

			var parent = tsoskAmGetSubParentSlug( $row ) || currentSection;
			if ( ! parent ) {
				return;
			}

			if ( ! subOrder[ parent ] ) {
				subOrder[ parent ]    = [];
				subOrderIds[ parent ] = [];
			}
			if ( subOrder[ parent ].indexOf( slug ) === -1 ) {
				subOrder[ parent ].push( slug );
			}
			if ( subOrderIds[ parent ].indexOf( id ) === -1 ) {
				subOrderIds[ parent ].push( id );
			}
		} );

		return {
			top_order     : topOrder,
			sub_order     : subOrder,
			sub_order_ids : subOrderIds
		};
	}

	var tsoskAmScrollActive = false;
	var tsoskAmScrollY      = 0;
	var tsoskAmScrollRaf    = null;

	function tsoskAmGetScrollTargets() {
		var targets = [];
		$( '.tsosk-content, #wpbody-content, #wpcontent, #wpwrap' ).each( function () {
			if ( this.scrollHeight > this.clientHeight + 2 ) {
				targets.push( this );
			}
		} );
		if ( document.scrollingElement ) {
			targets.push( document.scrollingElement );
		} else {
			targets.push( document.documentElement );
		}
		return targets;
	}

	function tsoskAmAutoScrollStep() {
		if ( ! tsoskAmScrollActive ) {
			tsoskAmScrollRaf = null;
			return;
		}

		var edge    = 80;
		var maxStep = 22;
		var y       = tsoskAmScrollY;

		function stepForRect( rect ) {
			if ( y < rect.top + edge ) {
				return -maxStep * Math.min( 1, ( rect.top + edge - y ) / edge );
			}
			if ( y > rect.bottom - edge ) {
				return maxStep * Math.min( 1, ( y - ( rect.bottom - edge ) ) / edge );
			}
			return 0;
		}

		tsoskAmGetScrollTargets().forEach( function ( el ) {
			var rect = ( el === document.documentElement || el === document.scrollingElement )
				? { top: 0, bottom: window.innerHeight }
				: el.getBoundingClientRect();
			var step = stepForRect( rect );
			if ( ! step ) {
				return;
			}
			if ( el === document.documentElement || el === document.scrollingElement ) {
				window.scrollBy( 0, step );
			} else {
				el.scrollTop += step;
			}
		} );

		tsoskAmScrollRaf = window.requestAnimationFrame( tsoskAmAutoScrollStep );
	}

	function tsoskAmBindDragScroll() {
		$( document ).on( 'mousemove.tsoskAmScroll touchmove.tsoskAmScroll', function ( e ) {
			tsoskAmUpdatePointerY( e );
		} );
	}

	function tsoskAmUnbindDragScroll() {
		$( document ).off( 'mousemove.tsoskAmScroll touchmove.tsoskAmScroll' );
	}

	function tsoskAmStartAutoScroll( event ) {
		tsoskAmUpdatePointerY( event );
		tsoskAmScrollActive = true;
		tsoskAmBindDragScroll();
		if ( ! tsoskAmScrollRaf ) {
			tsoskAmScrollRaf = window.requestAnimationFrame( tsoskAmAutoScrollStep );
		}
	}

	function tsoskAmStopAutoScroll() {
		tsoskAmScrollActive = false;
		tsoskAmUnbindDragScroll();
		if ( tsoskAmScrollRaf ) {
			window.cancelAnimationFrame( tsoskAmScrollRaf );
			tsoskAmScrollRaf = null;
		}
	}

	function tsoskAmUpdatePointerY( event ) {
		if ( event && typeof event.clientY === 'number' ) {
			tsoskAmScrollY = event.clientY;
			return;
		}
		var oe = event && event.originalEvent;
		if ( oe && oe.touches && oe.touches.length ) {
			tsoskAmScrollY = oe.touches[0].clientY;
		} else if ( oe && oe.changedTouches && oe.changedTouches.length ) {
			tsoskAmScrollY = oe.changedTouches[0].clientY;
		}
	}

	function tsoskAmInitSortable() {
		var $tbody = $( '#tsosk-am-tbody' );
		if ( ! $tbody.length || ! $.fn.sortable ) {
			return;
		}
		if ( $tbody.hasClass( 'ui-sortable' ) ) {
			$tbody.sortable( 'destroy' );
		}

		$tbody.sortable( {
			handle               : '.tsosk-am-handle',
			items                : '> tr.tsosk-am-row:not([hidden])',
			axis                 : 'y',
			appendTo               : document.body,
			scroll                 : false,
			tolerance              : 'pointer',
			distance               : 4,
			cursor                 : 'grabbing',
			forcePlaceholderSize   : true,
			cancel                 : 'input,textarea,select,button,a,label,.tsosk-am-toggle',
			helper                 : function ( e, $row ) {
				var $helper = $row.clone();
				$helper.children().each( function ( index ) {
					$( this ).width( $row.children().eq( index ).outerWidth() );
				} );
				$helper.css( {
					width    : $row.outerWidth(),
					display  : 'table',
					tableLayout : 'fixed',
					position : 'absolute',
					zIndex   : 100100
				} );
				return $helper;
			},
			start                : function ( event, ui ) {
				ui.item.addClass( 'tsosk-am-dragging' );
				ui.placeholder.height( ui.item.outerHeight() );
				tsoskAmStartAutoScroll( event );
			},
			sort             : function ( event ) {
				tsoskAmUpdatePointerY( event );
			},
			stop             : function ( event, ui ) {
				ui.item.removeClass( 'tsosk-am-dragging' );
				tsoskAmStopAutoScroll();
				var openSection = tsoskAmAssignParentFromDrag( ui.item );
				// Regroup now so sections move together with their children and the table matches what will be saved.
				window.setTimeout( function () { tsoskAmNormalizeRows( openSection ); }, 0 );
			}
		} );
	}

	function tsoskAmInit() {
		if ( ! $( '#tsosk-am-tbody' ).length ) {
			return;
		}
		tsoskAmInitSortable();
		tsoskAmExpandedSections = {};
		tsoskAmApplyCollapseState();
		var expandSections = $( '#tsosk-am-table' ).data( 'expand-sections' );
		if ( expandSections ) {
			String( expandSections ).split( ',' ).forEach( function ( slug ) {
				slug = $.trim( slug );
				if ( slug ) {
					tsoskAmSetSectionExpanded( slug, true );
				}
			} );
		}
	}

	$( tsoskAmInit );

	$( document ).on( 'click', '.tsosk-am-toggle', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		var $top = $( this ).closest( 'tr.tsosk-am-row-top' );
		tsoskAmToggleSection( tsoskAmSectionSlug( $top ) );
	} );

	$( document ).on( 'click', 'tr.tsosk-am-row-top.tsosk-am-has-children', function ( e ) {
		if ( $( e.target ).closest( '.tsosk-am-handle, input, select, textarea, button, a, label' ).length ) {
			return;
		}
		tsoskAmToggleSection( tsoskAmSectionSlug( $( this ) ) );
	} );

	$( document ).on( 'click', '#tsosk-am-expand-all', function ( e ) {
		e.preventDefault();
		tsoskAmExpandAllSections( true );
	} );

	$( document ).on( 'click', '#tsosk-am-collapse-all', function ( e ) {
		e.preventDefault();
		tsoskAmExpandAllSections( false );
	} );

	$( document ).on( 'change', '.tsosk-am-parent', function () {
		var $row = $( this ).closest( 'tr.tsosk-am-row-sub' );
		$row.attr( 'data-am-parent-section', $( this ).val() || '' );
		tsoskAmNormalizeRows();
	} );

	$( document ).on( 'change', '.tsosk-am-top-nest', function () {
		var nest = $.trim( $( this ).val() || '' );
		tsoskAmNormalizeRows( nest );
	} );

	$( document ).on( 'change', '.tsosk-am-hide', function () {
		var $row = $( this ).closest( 'tr' );
		var hidden = $( this ).is( ':checked' );
		$row.toggleClass( 'tsosk-am-row-is-hidden', hidden );
		$row.find( '.tsosk-am-label' ).prop( 'disabled', hidden );
	} );

	$( document ).on( 'click', '#tsosk-am-show-hidden', function ( e ) {
		e.preventDefault();
		var $section = $( '#tsosk-am-hidden-section' );
		if ( ! $section.length ) {
			return;
		}

		$section.addClass( 'is-highlighted' );
		setTimeout( function () {
			$section.removeClass( 'is-highlighted' );
		}, 2200 );

		var scrollTop = Math.max( 0, $section.offset().top - 100 );
		$( 'html, body, .tsosk-content' ).animate( { scrollTop: scrollTop }, 300 );

		var $first = $section.find( 'tr.tsosk-am-row' ).first();
		if ( $first.length ) {
			$first.addClass( 'tsosk-am-row-highlight' );
			setTimeout( function () {
				$first.removeClass( 'tsosk-am-row-highlight' );
			}, 2200 );
		}
	} );

	$( document ).on( 'click', '#tsosk-am-save', function () {
		var $btn  = $( this );
		var $msg  = $( '#tsosk-am-msg' );
		var nonce = $btn.data( 'nonce' );

		// Apply nest/unnest row layout, then read order from the table (sections stay collapsed).
		tsoskAmNormalizeRows();
		var sectionOrders = tsoskAmBuildSectionOrders();

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_am_save',
			data   : {
				nonce         : nonce,
				items         : JSON.stringify( tsoskAmCollectItems() ),
				sub_order     : JSON.stringify( sectionOrders.sub_order ),
				sub_order_ids : JSON.stringify( sectionOrders.sub_order_ids ),
				top_order     : JSON.stringify( sectionOrders.top_order )
			},
			success: function ( r ) {
				if ( r.success ) {
					var text = tsosk.i18n.done;
					if ( r.data && typeof r.data === 'object' && r.data.message ) {
						text = r.data.message;
					} else if ( typeof r.data === 'string' && r.data ) {
						text = r.data;
					}
					showMsg( $msg, text, 'ok' );
					setTimeout( function () { window.location.reload(); }, 700 );
				} else {
					var errText = tsosk.i18n.error;
					if ( typeof r.data === 'string' && r.data ) {
						errText = r.data;
					} else if ( r.data && r.data.message ) {
						errText = r.data.message;
					}
					showMsg( $msg, errText, 'error' );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	$( document ).on( 'click', '#tsosk-am-reset', function () {
		if ( ! window.confirm( tsosk.i18n.am_reset_confirm || 'Reset the admin menu to WordPress defaults?' ) ) {
			return;
		}
		var $btn  = $( this );
		var $msg  = $( '#tsosk-am-msg' );
		var nonce = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true );

		ajaxPost( {
			action : 'tsosk_am_reset',
			data   : { nonce: nonce },
			success: function ( r ) {
				if ( r.success ) {
					showMsg( $msg, r.data || tsosk.i18n.done, 'ok' );
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					showMsg( $msg, r.data || tsosk.i18n.error, 'error' );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				showMsg( $msg, tsosk.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} );
	} );

} )( jQuery );
