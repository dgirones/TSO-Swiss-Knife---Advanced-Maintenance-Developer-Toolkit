/**
 * TSO Swiss Knife – Admin JavaScript
 *
 * Handles all AJAX interactions for every module tab.
 * All data is read from data-* attributes on buttons so nonces
 * are always fresh (rendered server-side per request).
 *
 * @package TSO_Swiss_Knife
 * @since   1.0.0
 */

/* global tsosk, jQuery */
( function ( $ ) {
	'use strict';

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Show a brief status message inside an element, auto-hide after 4 s.
	 *
	 * @param {jQuery} $el      Target element.
	 * @param {string} msg      Message text.
	 * @param {string} [type]   'ok' | 'warn' | 'error'  (default 'ok').
	 * @param {number} [ttl]    Milliseconds before hiding (default 4000).
	 */
	function showMsg( $el, msg, type, ttl ) {
		// Keep layout classes the element already has (such as tsosk-um-install-msg): only swap the message type.
		$el.removeClass( 'tsosk-ajax-msg is-error tsosk-msg tsosk-msg--ok tsosk-msg--error tsosk-msg--warn' )
			.addClass( 'tsosk-msg tsosk-msg--' + ( type || 'ok' ) )
			.text( msg ).show();
		clearTimeout( $el.data( 'timer' ) );
		$el.data( 'timer', setTimeout( function () {
			$el.fadeOut( 300 );
		}, ttl || 4000 ) );
	}

	/**
	 * Generic AJAX POST wrapper.
	 *
	 * @param {Object}   params
	 * @param {string}   params.action   WP AJAX action slug.
	 * @param {Object}   params.data     Extra POST data.
	 * @param {Function} params.success  Callback( response ).
	 * @param {Function} [params.error]  Optional error callback( xhr ).
	 */
	function ajaxPost( params ) {
		var request = $.post(
			tsosk.ajax_url,
			$.extend( { action: params.action }, params.data ),
			function ( response ) {
				if ( params.success ) {
					params.success( response );
				}
			},
			'json'
		);
		request.fail( function ( xhr ) {
			if ( params.error ) {
				params.error( xhr );
			}
		} );
		if ( params.complete ) {
			request.always( params.complete );
		}
	}

	// ── Users & Sessions ───────────────────────────────────────────────────

	function tsoskUsersFill( template, value ) {
		template = template || '';
		if ( template.indexOf( '%s' ) !== -1 ) {
			return template.replace( '%s', value );
		}
		return value ? template + ' (' + value + ')' : template;
	}

	// Forget a #anchor so a reload opens the tab the user is on, not the one the anchor points to.
	function sqClearHash() {
		if ( window.location.hash && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.pathname + window.location.search );
		}
	}


	// ── Mobile tools menu ─────────────────────────────────────────────────────

	function tsoskMobileNavQuery() {
		return window.matchMedia( '(max-width: 782px)' );
	}

	function tsoskSetMobileNavOpen( open ) {
		var $wrap = $( '.tsosk-wrap' );
		var $toggle = $( '#tsosk-mobile-nav-toggle' );
		$wrap.toggleClass( 'tsosk-mobile-nav-open', open );
		if ( $toggle.length ) {
			$toggle.attr( 'aria-expanded', open ? 'true' : 'false' );
		}
		if ( open ) {
			tsoskScrollNavToLast();
		}
	}

	// The tools list reopens at the module used last: the open one, or else the last one tapped.
	function tsoskScrollNavToLast() {
		var list = document.getElementById( 'tsosk-nav-list' );
		if ( ! list ) {
			return;
		}
		var item = list.querySelector( '.tsosk-nav-item.is-active' );
		if ( ! item ) {
			var last = '';
			try { last = window.localStorage.getItem( 'tsosk_last_nav' ) || ''; } catch ( e ) { last = ''; }
			if ( last ) {
				var links = list.querySelectorAll( '.tsosk-nav-item' );
				for ( var i = 0; i < links.length; i++ ) {
					if ( links[ i ].getAttribute( 'href' ) === last ) {
						item = links[ i ];
						break;
					}
				}
			}
		}
		if ( ! item ) {
			return;
		}
		window.requestAnimationFrame( function () {
			var offset = item.getBoundingClientRect().top - list.getBoundingClientRect().top;
			list.scrollTop = Math.max( 0, list.scrollTop + offset - ( list.clientHeight / 2 ) + ( item.offsetHeight / 2 ) );
		} );
	}

	$( document ).on( 'click', '#tsosk-mobile-nav-toggle', function () {
		if ( ! tsoskMobileNavQuery().matches ) {
			return;
		}
		var open = ! $( '.tsosk-wrap' ).hasClass( 'tsosk-mobile-nav-open' );
		tsoskSetMobileNavOpen( open );
	} );

	$( document ).on( 'click', '.tsosk-sidebar-tools .tsosk-nav-item', function () {
		try { window.localStorage.setItem( 'tsosk_last_nav', this.getAttribute( 'href' ) || '' ); } catch ( e ) { /* storage blocked: the menu just opens at the top */ }
		if ( tsoskMobileNavQuery().matches ) {
			tsoskSetMobileNavOpen( false );
		}
	} );

	$( window ).on( 'resize.tsoskMobileNav', function () {
		if ( ! tsoskMobileNavQuery().matches ) {
			tsoskSetMobileNavOpen( false );
		}
	} );


	// ══ Sidebar Organiser ════════════════════════════════════════════════════

	( function () {
		var $sidebars    = $( '#tsosk-sidebars' );
		var $sidebar     = $( '#tsosk-sidebar-nav' );
		var $favSidebar  = $( '#tsosk-sidebar-favorites' );
		var $favList     = $( '#tsosk-favorites-list' );
		var $navList     = $( '#tsosk-nav-list' );
		var $editBar     = $( '#tsosk-sidebar-edit-bar' );
		var $actionsBar  = $( '#tsosk-sidebar-actions' );
		var $btnOrganise = $( '#tsosk-sidebar-organise' );
		var $btnSave     = $( '#tsosk-sidebar-save' );
		var $btnReset    = $( '#tsosk-sidebar-reset' );
		var $btnCancel   = $( '#tsosk-sidebar-cancel' );

		if ( ! $sidebar.length ) { return; }

		var nonce            = $sidebar.data( 'nonce' ) || $favSidebar.data( 'nonce' );
		var originalListHtml = null;
		var originalFavHtml  = null;
		var groupLabels      = tsosk.sidebar_groups || {};
		var groupOrder       = tsosk.sidebar_group_order || Object.keys( groupLabels );

		function rowSlug( $row ) {
			return $row.attr( 'data-slug' ) || '';
		}

		function destroyDragReorder() {
			if ( dragState ) {
				finishDrag( null, true );
			}
			$navList.find( '.tsosk-nav-edit-section-list' ).off( '.tsoskNavReorder' );
			$favList.off( '.tsoskNavReorder' );
			$( document ).off( '.tsoskNavReorder' );
			$( window ).off( '.tsoskNavReorder' );
			$( 'body > .tsosk-nav-drag-ghost' ).remove();
		}

		var dragState = null;

		function pointInRect( x, y, rect ) {
			return x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
		}

		function sectionGroupForList( $list ) {
			return $list.closest( '.tsosk-nav-edit-section' ).attr( 'data-group' ) || 'site';
		}

		function findTargetSectionList( clientX, clientY ) {
			var $target = null;

			$navList.find( '.tsosk-nav-edit-section' ).each( function () {
				var rect = this.getBoundingClientRect();
				if ( pointInRect( clientX, clientY, rect ) ) {
					$target = $( this ).find( '.tsosk-nav-edit-section-list' ).first();
					return false;
				}
			} );

			return $target;
		}

		function applyRowSection( $row, $list ) {
			if ( ! $row.length || ! $list || ! $list.length ) {
				return;
			}
			$row.attr( 'data-group', sectionGroupForList( $list ) );
		}

		function insertRowAtClientY( $list, $row, clientY ) {
			if ( ! $list.length || ! $row.length ) {
				return;
			}

			var inserted = false;

			$list.children( '.tsosk-nav-row' ).each( function () {
				var rect = this.getBoundingClientRect();
				if ( clientY < rect.top + ( rect.height / 2 ) ) {
					$( this ).before( $row );
					inserted = true;
					return false;
				}
			} );

			if ( ! inserted ) {
				$list.append( $row );
			}
		}

		function restoreRowOrigin( state ) {
			if ( ! state.$row || ! state.$row.length || ! state.$sourceList.length ) {
				return;
			}

			if ( state.originNext && document.contains( state.originNext ) ) {
				$( state.originNext ).before( state.$row );
				return;
			}

			state.$sourceList.append( state.$row );
		}

		function movePlaceholder( $list, clientY ) {
			if ( ! dragState || ! dragState.$placeholder ) {
				return;
			}
			var $placeholder = dragState.$placeholder;
			var $rows        = $list.children( '.tsosk-nav-row' );
			var inserted     = false;

			$rows.each( function () {
				var rect = this.getBoundingClientRect();
				var mid  = rect.top + ( rect.height / 2 );
				if ( clientY < mid ) {
					$( this ).before( $placeholder );
					inserted = true;
					return false;
				}
			} );

			if ( ! inserted ) {
				$list.append( $placeholder );
			}
		}

		function finishDrag( e, cancel ) {
			if ( ! dragState ) {
				return;
			}

			var state        = dragState;
			var clientY      = ( e && typeof e.clientY === 'number' ) ? e.clientY : state.lastClientY;
			var droppedOnFav = false;

			dragState = null;

			$( document ).off( '.tsoskNavReorder' );
			$( window ).off( '.tsoskNavReorder' );
			$navList.find( '.tsosk-nav-edit-section-list' ).removeClass( 'is-drop-hover' );
			$sidebars.removeClass( 'tsosk-nav-dragging' );
			$favList.removeClass( 'is-drop-target' );

			if ( state.$ghost && state.$ghost.length ) {
				state.$ghost.remove();
			}

			if ( state.$placeholder && state.$placeholder.length ) {
				state.$placeholder.remove();
			}

			if ( cancel || ! state.$row || ! state.$row.length ) {
				restoreRowOrigin( state );
				return;
			}

			if ( ! state.fromFavorites && $favList.length && typeof clientY === 'number' ) {
				droppedOnFav = pointInRect(
					e ? e.clientX : state.lastClientX,
					clientY,
					$favList[0].getBoundingClientRect()
				);
			}

			if ( state.fromFavorites ) {
				insertRowAtClientY( $favList, state.$row, clientY );
			} else {
				var $targetList = state.$targetList && state.$targetList.length
					? state.$targetList
					: state.$sourceList;

				if ( typeof clientY === 'number' ) {
					movePlaceholder( $targetList, clientY );
				}

				insertRowAtClientY( $targetList, state.$row, clientY );
				applyRowSection( state.$row, $targetList );
			}

			if ( droppedOnFav ) {
				addRowToFavorites( state.$row );
			}
		}

		function onDragMove( e ) {
			if ( ! dragState ) {
				return;
			}
			e.preventDefault();

			dragState.lastClientY = e.clientY;
			dragState.lastClientX = e.clientX;
			dragState.$ghost.css( 'top', ( e.clientY - dragState.offsetY ) + 'px' );

			if ( dragState.fromFavorites ) {
				movePlaceholder( $favList, e.clientY );
				return;
			}

			var $targetList = findTargetSectionList( e.clientX, e.clientY ) || dragState.$sourceList;
			dragState.$targetList = $targetList;
			$navList.find( '.tsosk-nav-edit-section-list' ).removeClass( 'is-drop-hover' );
			$targetList.addClass( 'is-drop-hover' );
			movePlaceholder( $targetList, e.clientY );

			if ( $favList.length ) {
				$favList.toggleClass(
					'is-drop-target',
					pointInRect( e.clientX, e.clientY, $favList[0].getBoundingClientRect() )
				);
			}
		}

		function onDragEnd( e ) {
			if ( ! dragState ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			finishDrag( e, false );
		}

		function startRowDrag( $list, $row, e, fromFavorites ) {
			if ( dragState ) {
				finishDrag( null, true );
			}

			e.preventDefault();
			e.stopPropagation();

			var rect         = $row[0].getBoundingClientRect();
			var originNext   = $row.next( '.tsosk-nav-row' )[0] || null;
			var $placeholder = $( '<div class="tsosk-sortable-placeholder" aria-hidden="true"></div>' );
			$placeholder.height( $row.outerHeight() );
			$row.before( $placeholder );
			$row.detach();

			var $ghost = $row.clone();
			$ghost.addClass( 'tsosk-nav-drag-ghost' );
			$ghost.find( '.tsosk-add-fav' ).remove();
			$( 'body' ).append( $ghost );
			$ghost.css( {
				position      : 'fixed',
				left          : rect.left + 'px',
				top           : rect.top + 'px',
				width         : rect.width + 'px',
				zIndex        : 100100,
				pointerEvents : 'none',
				margin        : 0
			} );

			dragState = {
				$row         : $row,
				$sourceList  : $list,
				$targetList  : $list,
				$placeholder : $placeholder,
				$ghost       : $ghost,
				offsetY      : e.clientY - rect.top,
				lastClientY  : e.clientY,
				lastClientX  : e.clientX,
				originNext   : originNext,
				fromFavorites: !! fromFavorites
			};

			$sidebars.addClass( 'tsosk-nav-dragging' );
			$favList.addClass( 'is-drop-target' );

			$( document )
				.on( 'pointermove.tsoskNavReorder mousemove.tsoskNavReorder touchmove.tsoskNavReorder', onDragMove );
			$( window )
				.on( 'pointerup.tsoskNavReorder pointercancel.tsoskNavReorder mouseup.tsoskNavReorder touchend.tsoskNavReorder', onDragEnd );
		}

		function initDragReorder() {
			$navList.find( '.tsosk-nav-edit-section-list' ).each( function () {
				var $list = $( this );
				$list.on( 'pointerdown.tsoskNavReorder', '.tsosk-drag-handle', function ( e ) {
					if ( ! $sidebars.hasClass( 'tsosk-editing' ) ) {
						return;
					}
					if ( 'mouse' === e.pointerType && 0 !== e.button ) {
						return;
					}
					var $row = $( this ).closest( '.tsosk-nav-row' );
					if ( ! $row.length ) {
						return;
					}
					startRowDrag( $list, $row, e, false );
				} );
			} );

			if ( ! $favList.length ) {
				return;
			}

			$favList.on( 'pointerdown.tsoskNavReorder', '.tsosk-drag-handle', function ( e ) {
				if ( ! $favSidebar.hasClass( 'tsosk-editing' ) ) {
					return;
				}
				if ( 'mouse' === e.pointerType && 0 !== e.button ) {
					return;
				}
				var $row = $( this ).closest( '.tsosk-nav-row' );
				if ( ! $row.length ) {
					return;
				}
				startRowDrag( $favList, $row, e, true );
			} );
		}

		function dedupeFavorite( $row ) {
			var slug = rowSlug( $row );
			if ( ! slug ) {
				$row.remove();
				return false;
			}
			var dupes = 0;
			$favList.find( '.tsosk-nav-row' ).each( function () {
				if ( rowSlug( $( this ) ) === slug ) {
					dupes += 1;
				}
			} );
			if ( dupes > 1 ) {
				$row.remove();
				return false;
			}
			$row.addClass( 'tsosk-fav-row' );
			$row.find( '.tsosk-hide-tab' ).remove();
			if ( ! $row.find( '.tsosk-remove-fav' ).length ) {
				$row.append(
					$( '<button type="button" class="tsosk-remove-fav"></button>' )
						.attr( 'title', tsosk.i18n.favorites_remove )
						.text( '✕' )
				);
			}
			updateFavoritesEmptyState();
			return true;
		}

		function updateFavoritesEmptyState() {
			var hasRows = $favList.find( '.tsosk-nav-row' ).length > 0;
			$favList.toggleClass( 'is-empty', ! hasRows );
		}

		function isFavoriteSlug( slug ) {
			var found = false;
			$favList.find( '.tsosk-nav-row' ).each( function () {
				if ( rowSlug( $( this ) ) === slug ) {
					found = true;
					return false;
				}
			} );
			return found;
		}

		function addRowToFavorites( $sourceRow ) {
			var slug = rowSlug( $sourceRow );
			if ( ! slug || isFavoriteSlug( slug ) ) {
				return false;
			}
			var $clone = $sourceRow.clone();
			$clone.removeClass( 'tsosk-dragging tsosk-drop-before tsosk-drop-after ui-sortable-helper' );
			$clone.find( '.tsosk-add-fav' ).remove();
			$favList.append( $clone );
			dedupeFavorite( $clone );
			return true;
		}

		function attachAddFavoriteButtons() {
			$navList.find( '.tsosk-nav-edit-section-list .tsosk-nav-row' ).each( function () {
				var $row = $( this );
				if ( $row.find( '.tsosk-add-fav' ).length ) {
					return;
				}
				$row.find( '.tsosk-hide-tab' ).before(
					$( '<button type="button" class="tsosk-add-fav" aria-label="★"></button>' )
						.attr( 'title', tsosk.i18n.favorites_add || 'Add to favorites' )
						.append( $( '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>' ) )
				);
			} );
		}

		/**
		 * Rebuild nav list into category blocks (all groups shown, including empty drop zones).
		 */
		function buildEditSections() {
			var buckets = {};

			groupOrder.forEach( function ( gid ) {
				buckets[ gid ] = [];
			} );

			$navList.find( '.tsosk-nav-row' ).each( function () {
				var $row = $( this );
				var gid  = $row.attr( 'data-group' ) || 'site';
				if ( ! buckets[ gid ] ) {
					buckets[ gid ] = [];
				}
				buckets[ gid ].push( $row );
			} );

			$navList.empty();

			groupOrder.forEach( function ( gid ) {
				var title = groupLabels[ gid ] || gid;
				var $sec  = $( '<div class="tsosk-nav-edit-section"></div>' ).attr( 'data-group', gid );
				$sec.append( $( '<div class="tsosk-nav-edit-section-title"></div>' ).text( title ) );
				var $list = $( '<div class="tsosk-nav-edit-section-list"></div>' );
				( buckets[ gid ] || [] ).forEach( function ( $row ) {
					$list.append( $row );
				} );
				$sec.append( $list );
				$navList.append( $sec );
			} );

			Object.keys( buckets ).forEach( function ( gid ) {
				if ( -1 !== groupOrder.indexOf( gid ) ) {
					return;
				}
				var title = groupLabels[ gid ] || gid;
				var $sec  = $( '<div class="tsosk-nav-edit-section"></div>' ).attr( 'data-group', gid );
				$sec.append( $( '<div class="tsosk-nav-edit-section-title"></div>' ).text( title ) );
				var $list = $( '<div class="tsosk-nav-edit-section-list"></div>' );
				buckets[ gid ].forEach( function ( $row ) {
					$list.append( $row );
				} );
				$sec.append( $list );
				$navList.append( $sec );
			} );
		}

		function getTabGroups() {
			var groups = {};

			$navList.find( '.tsosk-nav-edit-section' ).each( function () {
				var groupId = $( this ).attr( 'data-group' ) || 'site';
				$( this ).find( '.tsosk-nav-row' ).each( function () {
					var slug = rowSlug( $( this ) );
					if ( slug ) {
						groups[ slug ] = groupId;
					}
				} );
			} );

			return groups;
		}

		/* ── snapshot of current order ─────────────────────────────── */
		function getOrder() {
			var order = [];
			$navList.find( '.tsosk-nav-edit-section' ).each( function () {
				$( this ).find( '.tsosk-nav-row' ).each( function () {
					var slug = rowSlug( $( this ) );
					if ( slug ) {
						order.push( slug );
					}
				} );
			} );
			if ( order.length ) {
				return order;
			}
			return $navList.find( '.tsosk-nav-row' ).map( function () {
				return rowSlug( $( this ) );
			} ).get().filter( Boolean );
		}
		function getHidden() {
			return $navList.find( '.tsosk-nav-row.tsosk-nav-hidden' ).map( function () {
				return rowSlug( $( this ) );
			} ).get().filter( Boolean );
		}

		function getFavorites() {
			return $favList.find( '.tsosk-nav-row' ).map( function () {
				return rowSlug( $( this ) );
			} ).get().filter( Boolean );
		}

		/* ── Enter edit mode ────────────────────────────────────────── */
		$btnOrganise.on( 'click', function () {
			originalListHtml = $navList.html();
			originalFavHtml  = $favList.html();
			$sidebars.addClass( 'tsosk-editing' );
			$favSidebar.addClass( 'tsosk-editing' );
			$actionsBar.hide();
			$editBar.show();
			buildEditSections();
			attachAddFavoriteButtons();
			initDragReorder();
			updateFavoritesEmptyState();
		} );

		$( document ).on( 'click', '.tsosk-add-fav', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( ! $sidebars.hasClass( 'tsosk-editing' ) ) {
				return;
			}
			addRowToFavorites( $( this ).closest( '.tsosk-nav-row' ) );
		} );

		/* ── Cancel edit mode ───────────────────────────────────────── */
		$btnCancel.on( 'click', function () {
			exitEditMode( false );
		} );

		function exitEditMode( keepChanges ) {
			destroyDragReorder();
			if ( ! keepChanges ) {
				if ( null !== originalListHtml ) {
					$navList.html( originalListHtml );
				}
				if ( null !== originalFavHtml ) {
					$favList.html( originalFavHtml );
				}
			}
			$sidebars.removeClass( 'tsosk-editing' );
			$favSidebar.removeClass( 'tsosk-editing' );
			$editBar.hide();
			$actionsBar.show();
			originalListHtml = null;
			originalFavHtml  = null;
			updateFavoritesEmptyState();
		}

		/* ── Save ───────────────────────────────────────────────────── */
		$btnSave.on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( '…' );

			ajaxPost( {
				action : 'tsosk_save_sidebar_order',
				data   : {
					nonce         : nonce,
					order_json    : JSON.stringify( getOrder() ),
					groups_json   : JSON.stringify( getTabGroups() ),
					hidden_json   : JSON.stringify( getHidden() ),
					favorites_json: JSON.stringify( getFavorites() )
				},
				success: function ( r ) {
					if ( r.success ) {
						// Reload so PHP rebuilds grouped sidebar from saved flat order.
						window.location.reload();
						return;
					} else {
						alert( ( r.data || tsosk.i18n.error ) );
					}
					$btn.prop( 'disabled', false ).text( tsosk.i18n.sidebar_save );
				},
				error: function () {
					alert( tsosk.i18n.error );
					$btn.prop( 'disabled', false ).text( tsosk.i18n.sidebar_save );
				}
			} );
		} );

		/* ── Reset to default ───────────────────────────────────────── */
		$btnReset.on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			ajaxPost( {
				action : 'tsosk_reset_sidebar_order',
				data   : { nonce: nonce },
				success: function ( r ) {
					if ( r.success ) {
						window.location.reload();
					} else {
						alert( r.data || tsosk.i18n.error );
						$btn.prop( 'disabled', false );
					}
				},
				error: function () {
					alert( tsosk.i18n.error );
					$btn.prop( 'disabled', false );
				}
			} );
		} );

		/* ── Remove from favorites ──────────────────────────────────── */
		$( document ).on( 'click', '.tsosk-remove-fav', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( ! $favSidebar.hasClass( 'tsosk-editing' ) ) {
				return;
			}
			$( this ).closest( '.tsosk-nav-row' ).remove();
			updateFavoritesEmptyState();
		} );

		/* ── Hide / show tab toggle ─────────────────────────────────── */
		$( document ).on( 'click', '.tsosk-hide-tab', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var $row    = $( this ).closest( '.tsosk-nav-row' );
			var hidden  = $row.hasClass( 'tsosk-nav-hidden' );
			if ( hidden ) {
				$row.removeClass( 'tsosk-nav-hidden' );
				$( this ).attr( 'title', tsosk.i18n.sidebar_hide ).text( '✕' );
			} else {
				$row.addClass( 'tsosk-nav-hidden' );
				$( this ).attr( 'title', tsosk.i18n.sidebar_show ).text( '＋' );
			}
		} );

		updateFavoritesEmptyState();

	} )();

	// ── Global tab search (Fase 4) ────────────────────────────────────────────

	var tsoskSearchActive = -1;

	// Lowercase and strip accents so "configuración" finds "configuracion".
	function tsoskSearchNorm( text ) {
		text = String( text || '' ).toLowerCase();
		if ( text.normalize ) {
			text = text.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
		}
		return text;
	}

	function tsoskSearchEscape( text ) {
		return String( text || '' ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// Query tokens: normalised, without surrounding dots (".htaccess" -> "htaccess").
	function tsoskSearchTokens( query ) {
		return tsoskSearchNorm( query ).split( /\s+/ ).map( function ( token ) {
			return token.replace( /^\.+|\.+$/g, '' );
		} ).filter( function ( token ) {
			return token.length > 0;
		} );
	}

	// Score one token against one tab (0 = no match). Label beats slug beats keywords.
	function tsoskSearchTokenScore( item, token ) {
		var variants = [ token ];
		if ( token.length > 3 && token.slice( -1 ) === 's' ) {
			variants.push( token.slice( 0, -1 ) ); // "redirects" -> "redirect".
		}
		var label = item.label_n || tsoskSearchNorm( item.label );
		var slug  = item.slug || '';
		var kw    = ' ' + ( item.kw || '' ) + ' ';
		var group = item.group_n || tsoskSearchNorm( item.group );
		var best  = 0;
		variants.forEach( function ( v ) {
			var score = 0;
			if ( label.indexOf( v ) === 0 ) {
				score = 6;
			} else if ( label.indexOf( ' ' + v ) > -1 ) {
				score = 5;
			} else if ( label.indexOf( v ) > -1 ) {
				score = 4;
			} else if ( slug.indexOf( v ) > -1 ) {
				score = 3;
			} else if ( kw.indexOf( ' ' + v ) > -1 || kw.indexOf( '.' + v ) > -1 ) {
				score = 2;
			} else if ( kw.indexOf( v ) > -1 ) {
				score = 1;
			} else if ( group.indexOf( v ) > -1 ) {
				score = 0.5;
			}
			best = Math.max( best, score );
		} );
		return best;
	}

	// Keyword words that explain why a tab matched (shown under the result title).
	function tsoskSearchHints( item, tokens ) {
		var label = item.label_n || '';
		var words = ( item.kw || '' ).split( ' ' );
		var hints = [];
		tokens.forEach( function ( token ) {
			if ( label.indexOf( token ) > -1 ) {
				return;
			}
			for ( var i = 0; i < words.length; i++ ) {
				if ( words[ i ] && words[ i ].indexOf( token ) > -1 ) {
					if ( hints.indexOf( words[ i ] ) === -1 ) {
						hints.push( words[ i ] );
					}
					break;
				}
			}
		} );
		return hints.slice( 0, 3 );
	}

	function tsoskSearchFilter( query ) {
		var items  = tsosk.tab_search || [];
		var tokens = tsoskSearchTokens( query );
		if ( ! tokens.length ) {
			return items.slice( 0, 12 ).map( function ( item ) {
				return { item: item, hints: [] };
			} );
		}
		var scored = items.map( function ( item ) {
			var total = 0, matched = 0;
			tokens.forEach( function ( token ) {
				var sc = tsoskSearchTokenScore( item, token );
				if ( sc > 0 ) {
					matched++;
					total += sc;
				}
			} );
			return { item: item, total: total, matched: matched, hints: tsoskSearchHints( item, tokens ) };
		} );
		// All words must match; if nothing does, fall back to tabs matching at least one word.
		var strict = scored.filter( function ( row ) {
			return row.matched === tokens.length;
		} );
		var rows = strict.length ? strict : scored.filter( function ( row ) {
			return row.matched > 0;
		} );
		rows.sort( function ( a, b ) {
			return ( b.matched - a.matched ) || ( b.total - a.total );
		} );
		return rows.slice( 0, 12 );
	}

	function tsoskSearchRender( matches ) {
		var $list = $( '#tsosk-global-search-results' );
		if ( ! $list.length ) {
			return;
		}
		tsoskSearchActive = -1;
		if ( ! matches.length ) {
			$list.html(
				'<li><span class="tsosk-search-no-results">' +
				( tsosk.i18n.search_no_results || tsosk.i18n.no_matches ) +
				'</span></li>'
			).prop( 'hidden', false );
			return;
		}
		var html = '';
		matches.forEach( function ( row, i ) {
			var item = row.item;
			var sub  = tsoskSearchEscape( item.group );
			if ( row.hints && row.hints.length ) {
				sub += ' · ' + row.hints.map( tsoskSearchEscape ).join( ', ' );
			}
			html += '<li data-index="' + i + '">' +
				'<a href="' + tsoskSearchEscape( item.url ) + '" class="tsosk-search-result">' +
				tsoskSearchEscape( item.label ) +
				'<span class="tsosk-search-result-group">' + sub + '</span>' +
				'</a></li>';
		} );
		$list.html( html ).prop( 'hidden', false );
	}

	// ── Jump to a section inside a tab (links from the finder end in #tsosk-go=<heading text>) ──
	function tsoskFindSection( needle ) {
		var target = tsoskSearchNorm( needle );
		var best   = null;
		var bestScore = 0;
		var scope  = document.getElementById( 'wpbody-content' ) || document.body;
		$( scope ).find( 'h1, h2, h3, h4, h5, summary, legend, th, label, strong, .tsosk-card-title' ).each( function () {
			var $el = $( this );
			if ( $el.closest( '#tsosk-global-search-results, #adminmenu, .tsosk-sidebar, .tsosk-favs, script, style' ).length ) {
				return;
			}
			var txt = tsoskSearchNorm( $el.text().replace( /\s+/g, ' ' ).trim() );
			if ( ! txt || txt.indexOf( target ) === -1 ) {
				return;
			}
			var score = txt.indexOf( target ) === 0 ? 3 : 1;
			if ( /^H[1-5]$/.test( this.tagName ) ) {
				score += 2;
			}
			if ( score > bestScore ) {
				best      = this;
				bestScore = score;
			}
		} );
		return best;
	}

	function tsoskGoToSection( attempt ) {
		var m = /^#tsosk-go=(.+)$/.exec( window.location.hash || '' );
		if ( ! m ) {
			return;
		}
		var needle;
		try {
			needle = decodeURIComponent( m[ 1 ] );
		} catch ( e ) {
			return;
		}
		var el = tsoskFindSection( needle );
		if ( ! el ) {
			if ( attempt < 4 ) {
				window.setTimeout( function () {
					tsoskGoToSection( attempt + 1 );
				}, 400 );
			}
			return;
		}
		$( el ).parents( 'details' ).prop( 'open', true );
		if ( el.tagName === 'SUMMARY' ) {
			$( el ).parent( 'details' ).prop( 'open', true );
		}
		var $box = $( el ).closest( '.tsosk-card, details, .tsosk-section' );
		var $hit = $box.length ? $box.first() : $( el );
		var top  = $hit.offset().top - 80;
		window.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
		$hit.addClass( 'tsosk-section-flash' );
		window.setTimeout( function () {
			$hit.removeClass( 'tsosk-section-flash' );
		}, 2800 );
	}

	$( function () {
		tsoskGoToSection( 0 );
	} );
	$( window ).on( 'hashchange', function () {
		tsoskGoToSection( 0 );
	} );

	function tsoskSearchNavigate( url ) {
		if ( url ) {
			window.location.href = url;
		}
	}

	function tsoskSearchSetActive( index ) {
		var $items = $( '#tsosk-global-search-results li[data-index]' );
		$items.removeClass( 'is-active' );
		tsoskSearchActive = index;
		if ( index >= 0 && index < $items.length ) {
			$items.eq( index ).addClass( 'is-active' );
		}
	}

	$( document ).on( 'input', '#tsosk-global-search-input', function () {
		tsoskSearchRender( tsoskSearchFilter( $( this ).val() ) );
	} );

	$( document ).on( 'focus', '#tsosk-global-search-input', function () {
		tsoskSearchRender( tsoskSearchFilter( $( this ).val() ) );
	} );

	$( document ).on( 'keydown', '#tsosk-global-search-input', function ( e ) {
		var $list    = $( '#tsosk-global-search-results' );
		var $links   = $list.find( 'li[data-index] a' );
		var count    = $links.length;

		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			tsoskSearchSetActive( count ? ( ( tsoskSearchActive + 1 ) % count ) : -1 );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			tsoskSearchSetActive( count ? ( ( tsoskSearchActive - 1 + count ) % count ) : -1 );
		} else if ( e.key === 'Enter' ) {
			if ( tsoskSearchActive >= 0 && $links.eq( tsoskSearchActive ).length ) {
				e.preventDefault();
				tsoskSearchNavigate( $links.eq( tsoskSearchActive ).attr( 'href' ) );
			}
		} else if ( e.key === 'Escape' ) {
			$list.prop( 'hidden', true );
			$( this ).blur();
		}
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '#tsosk-global-search' ).length ) {
			$( '#tsosk-global-search-results' ).prop( 'hidden', true );
		}
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'k' ) {
			var $input = $( '#tsosk-global-search-input' );
			if ( $input.length ) {
				e.preventDefault();
				$input.trigger( 'focus' ).select();
			}
		}
	} );

	// ── Developer mode preset ─────────────────────────────────────────────────

	function tsoskDeveloperMode( enable, $msg ) {
		var nonce = tsosk.debug_nonce || $( '#tsosk-debug-developer-on' ).data( 'nonce' );
		if ( ! nonce ) {
			return;
		}
		ajaxPost( {
			action : 'tsosk_debug_developer_mode',
			data   : { nonce: nonce, enable: enable ? 1 : 0 },
			success: function ( r ) {
				var text = r.success ? ( r.data && r.data.message ? r.data.message : tsosk.i18n.done ) : ( r.data || tsosk.i18n.error );
				if ( $msg && $msg.length ) {
					showMsg( $msg, text, r.success ? 'ok' : 'error' );
				}
				if ( r.success ) {
					window.location.reload();
				}
			},
			error: function () {
				if ( $msg && $msg.length ) {
					showMsg( $msg, tsosk.i18n.error, 'error' );
				}
			}
		} );
	}

	$( document ).on( 'click', '#tsosk-debug-developer-on, #tsosk-header-dev-mode-on', function () {
		tsoskDeveloperMode( true, $( '#tsosk-debug-msg' ) );
	} );

	$( document ).on( 'click', '#tsosk-debug-developer-off', function () {
		tsoskDeveloperMode( false, $( '#tsosk-debug-msg' ) );
	} );

	/* ── Info popovers (long table notes) ─────────────────────────────────── */
	function tsoskCloseInfoPops() {
		$( '.tsosk-info-pop:not([hidden])' ).prop( 'hidden', true );
		$( '.tsosk-info-btn[aria-expanded="true"]' ).attr( 'aria-expanded', 'false' );
	}

	$( document ).on( 'click', '.tsosk-info-btn', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		var $btn  = $( this );
		var $pop  = $btn.siblings( '.tsosk-info-pop' ).first();
		var isOpen = ! $pop.prop( 'hidden' );
		tsoskCloseInfoPops();
		if ( isOpen ) {
			return;
		}
		$pop.prop( 'hidden', false );
		$btn.attr( 'aria-expanded', 'true' );

		var rect = this.getBoundingClientRect();
		var popW = $pop.outerWidth();
		var popH = $pop.outerHeight();
		var left = Math.max( 12, Math.min( rect.left, window.innerWidth - popW - 12 ) );
		var top  = rect.bottom + 6;
		if ( top + popH > window.innerHeight - 12 && rect.top - popH - 6 > 12 ) {
			top = rect.top - popH - 6;
		}
		$pop.css( { left: left + 'px', top: top + 'px' } );
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.tsosk-info' ).length ) {
			tsoskCloseInfoPops();
		}
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			tsoskCloseInfoPops();
		}
	} );
	$( window ).on( 'resize', tsoskCloseInfoPops );

	// ── Phones: label the cells of data tables so each row can stack as a compact card ──

	function tsoskLabelTables() {
		$( '.tsosk-content table.tsosk-table, .tsosk-content .tsosk-table-wrap > table' ).each( function () {
			var $t = $( this );
			var state = $t.attr( 'data-tsosk-stack' );
			if ( 'module' === state || $t.is( '#tsosk-am-table' ) || $t.hasClass( 'tsosk-no-stack' ) ) {
				return;
			}
			var $heads = $t.children( 'thead' ).first().find( 'th, td' );
			if ( $heads.length < 3 ) {
				return;
			}
			if ( ! state ) {
				// Tables that already label their own cells keep their own phone layout.
				if ( $t.children( 'tbody' ).first().find( 'td[data-label]' ).length ) {
					$t.attr( 'data-tsosk-stack', 'module' );
					return;
				}
				$t.attr( 'data-tsosk-stack', 'own' );
			}
			var labels = $heads.map( function () {
				return $.trim( $( this ).text() );
			} ).get();
			$t.children( 'tbody' ).children( 'tr' ).each( function () {
				var $cells = $( this ).children( 'td, th' );
				if ( $cells.length !== labels.length ) {
					return;
				}
				$cells.each( function ( i ) {
					if ( labels[ i ] && ! this.hasAttribute( 'data-label' ) ) {
						this.setAttribute( 'data-label', labels[ i ] );
					}
				} );
			} );
		} );
	}

	var tsoskLabelTimer = null;
	function tsoskLabelTablesSoon() {
		window.clearTimeout( tsoskLabelTimer );
		tsoskLabelTimer = window.setTimeout( tsoskLabelTables, 120 );
	}

	$( tsoskLabelTables );
	if ( window.MutationObserver ) {
		var tsoskLabelTarget = document.querySelector( '.tsosk-content' );
		if ( tsoskLabelTarget ) {
			new window.MutationObserver( tsoskLabelTablesSoon ).observe( tsoskLabelTarget, { childList: true, subtree: true } );
		}
	}

	// Shared helpers and state exposed to the per-tab modules (assets/js/modules/tsosk-*.js).
	window.tsoskAdmin = {
		ajaxPost: ajaxPost,
		showMsg: showMsg,
		tsoskUsersFill: tsoskUsersFill,
		sqClearHash: sqClearHash,
		tsoskCloseInfoPops: tsoskCloseInfoPops
	};


} )( jQuery );