/* 计划任务管理器交互：运行 / 暂停 / 恢复 / 删除 / 新增。
 * 依赖 jQuery（由设置页统一 enqueue）。所有写操作经后台 manage_options + nonce 守卫。 */
( function ( $ ) {
	'use strict';

	function jycCronNonce() {
		return $( '#jyc-cron-nonce' ).val() || '';
	}

	function jycCronPost( data, btn, done ) {
		if ( btn ) {
			var old = $( btn ).text();
			$( btn ).prop( 'disabled', true ).text( jinyuCronI18n.busy || '处理中…' );
		}
		$.ajax( {
			url: window.ajaxurl,
			method: 'POST',
			dataType: 'json',
			data: $.extend( { action: 'jinyu_cron_action', jinyu_cron_nonce: jycCronNonce() }, data ),
			success: function ( res ) {
				if ( res && res.success ) {
					if ( res.html ) {
						$( '#jyc-cronList' ).html( res.html );
					}
					jycCronToast( res.msg || jinyuCronI18n.done );
				} else {
					jycCronToast( ( res && res.msg ) || jinyuCronI18n.error, true );
				}
			},
			error: function () {
				jycCronToast( jinyuCronI18n.error, true );
			},
			complete: function () {
				if ( btn ) {
					$( btn ).prop( 'disabled', false ).text( old );
				}
				if ( done ) {
					done();
				}
			}
		} );
	}

	function jycCronToast( msg, isErr ) {
		var $t = $( '#jyc-toast' );
		if ( ! $t.length ) {
			return;
		}
		$t.find( '#jyc-toastMsg' ).text( msg );
		$t.toggleClass( 'jyc-show', true );
		if ( isErr ) {
			$t.addClass( 'jyc-toast--err' );
		}
		setTimeout( function () {
			$t.removeClass( 'jyc-show jyc-toast--err' );
		}, 2600 );
	}

	$( function () {
		// 事件委托：行内操作按钮（列表会被重新渲染，必须委托到静态父级）。
		$( document ).on( 'click', '#jyc-cronList .jyc-cron-act button[data-act]', function ( e ) {
			e.preventDefault();
			var $b = $( this );
			jycCronPost(
				{
					act: $b.data( 'act' ),
					hook: $b.data( 'hook' ),
					time: $b.data( 'time' ) || 0
				},
				this
			);
		} );

		// 新增自定义事件。
		$( document ).on( 'click', '#jyc-cronAdd', function ( e ) {
			e.preventDefault();
			var hook = $.trim( $( '#jyc-cron-hook' ).val() || '' );
			var interval = parseInt( $( '#jyc-cron-int' ).val(), 10 );
			if ( ! hook || ! interval || interval < 1 ) {
				jycCronToast( jinyuCronI18n.invalid, true );
				return;
			}
			jycCronPost( { act: 'add', hook: hook, interval: interval }, this, function () {
				$( '#jyc-cron-hook' ).val( '' );
				$( '#jyc-cron-int' ).val( '' );
			} );
		} );

		// 窄屏 tips 按钮：点击切换解释气泡（fixed 定位，列表重渲染后仍有效）。
		$( document ).on( 'click', '#jyc-cronList .jyc-cron-tip', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var $btn = $( this );
			var txt = $btn.data( 'tip' ) || '';
			var $pop = $( '#jyc-cron-tippop' );
			if ( ! $pop.length ) {
				$pop = $( '<div id="jyc-cron-tippop" class="jyc-cron-tippop" role="tooltip"></div>' ).appendTo( document.body );
			}
			if ( $pop.is( ':visible' ) && $pop.data( 'owner' ) === this ) {
				$pop.hide();
				return;
			}
			$pop.text( txt ).data( 'owner', this ).show();
			var r = this.getBoundingClientRect();
			var pw = $pop.outerWidth();
			var left = Math.min( r.left, window.innerWidth - pw - 10 );
			left = Math.max( 8, left );
			$pop.css( { top: r.bottom + 8, left: left } );
		} );

		// 点击空白处或 Esc 关闭气泡。
		$( document ).on( 'click', function ( e ) {
			var $pop = $( '#jyc-cron-tippop' );
			if ( $pop.length && $pop.is( ':visible' ) && ! $( e.target ).closest( '.jyc-cron-tippop, .jyc-cron-tip' ).length ) {
				$pop.hide();
			}
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				$( '#jyc-cron-tippop' ).hide();
			}
		} );
	} );

	window.jinyuCronI18n = window.jinyuCronI18n || {
		busy: '处理中…',
		done: '已完成',
		error: '操作失败',
		invalid: 'Hook 与间隔为必填'
	};
} )( jQuery );
