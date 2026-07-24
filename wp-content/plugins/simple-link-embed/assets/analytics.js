( function() {
    'use strict';

    // 設定確認
    if ( typeof slembAnalytics === 'undefined' || ! slembAnalytics.enabled ) {
        return;
    }

    const measurementId = ( slembAnalytics.measurementId || '' ).toString().trim().toUpperCase();
    if ( ! /^G-[A-Z0-9]+$/.test( measurementId ) ) {
        return;
    }
    slembAnalytics.measurementId = measurementId;

    /**
     * クリックイベントハンドラー
     */
    function handleClick( e ) {
        const card = e.target.closest( '[data-track-click]' );

        if ( ! card ) {
            return;
        }

        // イベントデータ収集
        const eventData = {
            link_url: card.getAttribute( 'data-track-url' ) || '',
            card_title: card.getAttribute( 'data-track-title' ) || '',
            link_domain: card.getAttribute( 'data-track-domain' ) || '',
            page_title: document.title || '',
            page_url: window.location.href || ''
        };

        // GA4にイベント送信
        sendGA4Event( eventData );
    }

    /**
     * GA4イベント送信
     */
    function sendGA4Event( eventData ) {
        if ( typeof gtag === 'undefined' ) {
            console.warn( 'Simple Link Embed: gtag not found. Please ensure GA4 is installed.' );
            return;
        }

        const eventName = slembAnalytics.eventName || 'link_card_click';

        gtag( 'event', eventName, {
            link_url: eventData.link_url,
            card_title: eventData.card_title,
            link_domain: eventData.link_domain,
            page_title: eventData.page_title,
            page_url: eventData.page_url,
            send_to: slembAnalytics.measurementId
        } );
    }

    // イベントデリゲーションでクリックを監視
    document.addEventListener( 'click', handleClick, true );

} )();
