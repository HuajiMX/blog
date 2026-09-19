/**
 * Simple Link Embed - エディター用JavaScript
 *
 * @package Slemb
 */

( function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { createElement, useEffect, useRef, Fragment, useState } = wp.element;
    const { TextControl, Spinner, SelectControl, ToggleControl, PanelBody, Icon, Modal, ToolbarButton } = wp.components;
    const { useBlockProps, InspectorControls, LinkControl, BlockControls } = wp.blockEditor;
    const sampleImageUrl = ( typeof slembData !== 'undefined' && slembData.pluginUrl )
        ? slembData.pluginUrl + 'assets/images/sample-image.jpg'
        : '';
    const siteIconUrl = ( typeof slembData !== 'undefined' && slembData.pluginUrl )
        ? slembData.pluginUrl + 'assets/images/site-placeholder.svg'
        : '';

    /**
     * URLからOGPデータを取得
     */
    async function fetchOGP( url ) {
        if ( ! url || typeof slembData === 'undefined' ) {
            return null;
        }

        const apiUrl = slembData.apiUrl + '?url=' + encodeURIComponent( url );
        const response = await fetch( apiUrl, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': slembData.nonce || ''
            }
        } );

        if ( ! response.ok ) {
            throw new Error( 'HTTP ' + response.status );
        }

        return response.json();
    }

    /**
     * Editコンポーネント
     */
    function Edit( props ) {
        const { attributes, setAttributes } = props;
        const { url, ogpData, isLoading, error, imagePosition, showSiteName, showDescription, openInNewTab } = attributes;
        const isInitialMount = useRef( true );
        const debounceTimerRef = useRef( null );
        const blockProps = useBlockProps();
        const [inputValue, setInputValue] = useState( url );
        const [isLinkModalOpen, setIsLinkModalOpen] = useState( false );
        const [modalUrl, setModalUrl] = useState( url );
        const fetchErrorMessage = __( 'Failed to fetch OGP data. Please check the URL.', 'simple-link-embed' );
        const reloadErrorMessage = __( 'Failed to refresh OGP data.', 'simple-link-embed' );

        const applyOgpSuccess = ( data ) => {
            setAttributes( {
                ogpData: data,
                isLoading: false,
                error: ''
            } );
        };

        const applyOgpError = ( message ) => {
            setAttributes( {
                ogpData: null,
                isLoading: false,
                error: message
            } );
        };

        const loadOgpData = async ( targetUrl ) => {
            const data = await fetchOGP( targetUrl );
            applyOgpSuccess( data );
        };

        // URL変更時のOGP取得（デバウンス）
        useEffect( () => {
            // 初回マウント時はスキップ
            if ( isInitialMount.current ) {
                isInitialMount.current = false;
                return;
            }

            // URLが空の場合
            if ( ! url ) {
                setAttributes( {
                    ogpData: null,
                    isLoading: false,
                    error: ''
                } );
                return;
            }

            // 前回のタイマーをクリア
            if ( debounceTimerRef.current ) {
                clearTimeout( debounceTimerRef.current );
            }

            // ローディング開始
            setAttributes( {
                isLoading: true,
                error: ''
            } );

            // デバウンス（800ms）
            debounceTimerRef.current = setTimeout( async () => {
                try {
                    await loadOgpData( url );
                } catch ( err ) {
                    applyOgpError( fetchErrorMessage );
                }
            }, 800 );

            return () => {
                if ( debounceTimerRef.current ) {
                    clearTimeout( debounceTimerRef.current );
                    debounceTimerRef.current = null;
                }
            };
        }, [ url ] );

        // URL 値の同期
        useEffect( () => {
            setInputValue( url );
            setModalUrl( url );
        }, [ url ] );

        const openLinkModal = () => {
            if ( isLinkModalOpen ) {
                return;
            }
            setModalUrl( url || '' );
            setIsLinkModalOpen( true );
        };

        const closeLinkModal = () => {
            setIsLinkModalOpen( false );
        };

        // OGP情報を再取得
        const onReload = async () => {
            if ( ! url ) {
                return;
            }

            // キャッシュをクリアして再取得
            setAttributes( { isLoading: true, error: '' } );
            
            try {
                // キャッシュクリアAPIを呼び出し
                const clearUrl = slembData.cacheClearUrl + '?url=' + encodeURIComponent( url );
                const clearResponse = await fetch( clearUrl, {
                    method: 'DELETE',
                    headers: {
                        'X-WP-Nonce': slembData.nonce || '',
                    },
                } );

                if ( ! clearResponse.ok ) {
                    throw new Error( 'HTTP ' + clearResponse.status );
                }

                // キャッシュ削除後に最新情報を取得
                await loadOgpData( url );
            } catch ( err ) {
                setAttributes( { isLoading: false, error: reloadErrorMessage } );
            }
        };

        // カードプレビューのレンダリング
        const renderPreview = () => {
            // ローディング中
            if ( isLoading ) {
                return createElement( 'div', { className: 'slemb-editor-preview slemb-loading' },
                    createElement( Spinner, {} ),
                    createElement( 'p', { className: 'slemb-loading-text' }, __( 'Fetching OGP data...', 'simple-link-embed' ) )
                );
            }

            // エラー表示
            if ( error ) {
                return createElement( 'div', { className: 'slemb-editor-preview slemb-error' },
                    createElement( 'p', { className: 'slemb-error-text' }, error )
                );
            }

            // URL未入力
            if ( ! url ) {
                return createElement( 'div', { className: 'slemb-editor-preview slemb-placeholder' },
                    createElement( 'p', {}, __( 'A preview will appear here.', 'simple-link-embed' ) )
                );
            }

            // OGPデータあり
            if ( ogpData && ogpData.title ) {
                const { title, description, image, domain, site_name, favicon } = ogpData;
                const displaySite = site_name || domain;
                const safeDescription = ( description || '' ).trim();
                const siteIconSrc = favicon || siteIconUrl;

                // カードのクラス名を生成
                const cardClasses = ['slemb-card'];
                if ( imagePosition === 'right' ) {
                    cardClasses.push( 'slemb-card--image-right' );
                } else if ( imagePosition === 'hide' || !image ) {
                    cardClasses.push( 'slemb-card--no-image' );
                }

                // リンクのprops
                const linkProps = {
                    href: url,
                    className: cardClasses.join( ' ' ),
                    onClick: function( e ) { e.preventDefault(); }
                };
                if ( openInNewTab ) {
                    linkProps.target = '_blank';
                    linkProps.rel = 'noopener noreferrer';
                }

                return createElement( 'div', { className: 'slemb-editor-preview' },
                    createElement( 'a', linkProps,
                        ( image && imagePosition !== 'hide' ) ? createElement( 'div', { className: 'slemb-card__image' },
                            createElement( 'img', {
                                src: image,
                                alt: title,
                                loading: 'lazy'
                            } )
                        ) : null,
                        createElement( 'div', { className: 'slemb-card__content' },
                            createElement( 'div', { className: 'slemb-card__title' }, title ),
                            ( showDescription && safeDescription ) ? createElement( 'p', { className: 'slemb-card__description' }, safeDescription ) : null,
                            ( showSiteName && displaySite ) ? createElement( 'div', { className: 'slemb-card__site' },
                                siteIconUrl ? createElement( 'img', {
                                    className: 'slemb-card__favicon',
                                    src: siteIconSrc,
                                    'data-fallback-icon': siteIconUrl,
                                    alt: '',
                                    'aria-hidden': true,
                                    onError: function( event ) {
                                        event.currentTarget.onerror = null;
                                        event.currentTarget.src = siteIconUrl;
                                    }
                                } ) : null,
                                displaySite ? createElement( 'span', { className: 'slemb-card__domain' }, displaySite ) : null
                            ) : null
                        )
                    )
                );
            }

            // URLあるけどOGPなし
            return createElement( 'div', { className: 'slemb-editor-preview slemb-loading' },
                createElement( Spinner, {} )
            );
        };

        return createElement( Fragment, {},
            isLinkModalOpen && createElement( Modal, {
                title: __( 'Search posts or enter a URL', 'simple-link-embed' ),
                onRequestClose: closeLinkModal,
                className: 'slemb-link-modal'
            },
                createElement( 'div', { className: 'slemb-link-modal__body' },
                    createElement( LinkControl, {
                        value: { url: modalUrl || '' },
                        forceIsEditingLink: true,
                        onChange: ( link ) => {
                            const newUrl = link?.url || '';
                            setModalUrl( newUrl );
                            setInputValue( newUrl );
                            setAttributes( { url: newUrl } );
                            setIsLinkModalOpen( false );
                        },
                        onCancel: closeLinkModal,
                        placeholder: __( 'Search posts or enter a URL.', 'simple-link-embed' ),
                        settings: [],
                        showInitialSuggestions: true,
                        allowDirectEntry: true,
                        withCreateSuggestion: false,
                        suggestionsQuery: {
                            type: 'post',
                            subtype: 'any',
                        }
                    } )
                )
            ),
            createElement( InspectorControls, {},
                createElement( PanelBody, { title: __( 'Card settings', 'simple-link-embed' ), initialOpen: true },
                    createElement( SelectControl, {
                        label: __( 'Image position', 'simple-link-embed' ),
                        value: imagePosition,
                        options: [
                            { label: __( 'Left', 'simple-link-embed' ), value: 'left' },
                            { label: __( 'Right', 'simple-link-embed' ), value: 'right' },
                            { label: __( 'Hidden', 'simple-link-embed' ), value: 'hide' }
                        ],
                        onChange: function( value ) { setAttributes( { imagePosition: value } ); }
                    } ),
                    createElement( ToggleControl, {
                        label: __( 'Show description', 'simple-link-embed' ),
                        checked: showDescription,
                        onChange: function( value ) { setAttributes( { showDescription: value } ); }
                    } ),
                    createElement( ToggleControl, {
                        label: __( 'Show site name', 'simple-link-embed' ),
                        checked: showSiteName,
                        onChange: function( value ) { setAttributes( { showSiteName: value } ); }
                    } ),
                    createElement( ToggleControl, {
                        label: __( 'Open in a new tab', 'simple-link-embed' ),
                        checked: openInNewTab,
                        onChange: function( value ) { setAttributes( { openInNewTab: value } ); }
                    } ),
                    // OGP情報更新ボタン
                    url && createElement( 'div', { style: { marginTop: '1rem', paddingTop: '1rem', borderTop: '1px solid #e0e0e0' } },
                        createElement( 'button', {
                            className: 'components-button is-secondary',
                            onClick: onReload,
                            disabled: isLoading,
                            style: { 
                                width: '100%', 
                                display: 'flex', 
                                alignItems: 'center', 
                                justifyContent: 'center', 
                                gap: '4px',
                                lineHeight: '1'
                            }
                        }, 
                            !isLoading && createElement( Icon, { icon: 'update-alt', style: { width: '16px', height: '16px', fontSize: '16px' } } ),
                            isLoading ? createElement( Spinner, { style: { width: '16px', height: '16px', margin: 0 } } ) : null,
                            isLoading ? __( 'Refreshing...', 'simple-link-embed' ) : __( 'Refresh OGP data', 'simple-link-embed' )
                        )
                    )
                )
            ),
            // ツールバーにリロードボタンを追加
            url && createElement( BlockControls, null,
                createElement( ToolbarButton, {
                    icon: 'update-alt',
                    label: __( 'Refresh OGP data', 'simple-link-embed' ),
                    onClick: onReload,
                    disabled: isLoading,
                } )
            ),
            createElement( 'div', {
                ...blockProps,
                className: (blockProps.className ? blockProps.className + ' ' : '') + 'slemb-editor-wrapper'
            },
                createElement( 'div', { className: 'slemb-editor-controls' },
                    createElement( 'div', { className: 'slemb-control-header' },
                        createElement( 'label', { className: 'slemb-control-label' }, __( 'Search or enter a URL', 'simple-link-embed' ) ),
                        createElement( 'div', { 
                        className: 'slemb-plugin-header',
                        style: { display: 'flex', alignItems: 'center', gap: '6px' }
                    },
                        createElement( 'svg', {
                            xmlns: 'http://www.w3.org/2000/svg',
                            viewBox: '0 0 24 24',
                            fill: 'none',
                            strokeWidth: 1.5,
                            stroke: '#CCC',
                            width: 16,
                            height: 16
                        }, createElement( 'path', {
                            strokeLinecap: 'round',
                            strokeLinejoin: 'round',
                            fill: 'none',
                            d: 'M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z'
                        })),
                        createElement( 'span', { className: 'slemb-plugin-name' }, 'Simple Link Embed' )
                    )
                    ),
                    createElement( TextControl, {
                        className: 'slemb-url-input-trigger',
                        label: '',
                        value: inputValue,
                        placeholder: __( 'Click to search posts or enter a URL.', 'simple-link-embed' ),
                        readOnly: true,
                        onClick: openLinkModal,
                        onKeyDown: ( event ) => {
                            if ( event.key === 'Enter' || event.key === ' ' ) {
                                event.preventDefault();
                                openLinkModal();
                            }
                        }
                    } )
                ),
                renderPreview()
            )
        );
    }

    // ブロックの登録
    registerBlockType( 'simple-link-embed/card', {
        apiVersion: 3,
        title: 'Simple Link Embed',
        icon: wp.element.createElement('svg', {
            xmlns: 'http://www.w3.org/2000/svg',
            viewBox: '0 0 24 24',
            fill: 'none',
            strokeWidth: 1.5,
            stroke: '#000000',
            style: { fill: 'none' }
        }, wp.element.createElement('path', {
            strokeLinecap: 'round',
            strokeLinejoin: 'round',
            fill: 'none',
            style: { fill: 'none' },
            d: 'M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z'
        })),
        category: 'embed',
        description: __( 'Automatically fetches OGP data from a URL and displays it as a blog card.', 'simple-link-embed' ),
        keywords: ['link', 'card', 'ogp', 'blog card'],
        textdomain: 'simple-link-embed',
        attributes: {
            url: {
                type: 'string',
                default: ''
            },
            ogpData: {
                type: 'object',
                default: null
            },
            isLoading: {
                type: 'boolean',
                default: false
            },
            error: {
                type: 'string',
                default: ''
            },
            imagePosition: {
                type: 'string',
                default: 'left'
            },
            showSiteName: {
                type: 'boolean',
                default: true
            },
            showDescription: {
                type: 'boolean',
                default: true
            },
            openInNewTab: {
                type: 'boolean',
                default: true
            }
        },
        supports: {
            align: ['wide', 'full'],
            spacing: {
                margin: true,
                padding: true
            },
            html: false,
            multiple: true,
            reorder: true
        },
        edit: Edit,
        save: function() {
            // サーバーサイドレンダリングを使用するため null を返す
            return null;
        },
        example: {
            attributes: {
                url: 'https://example.com',
                ogpData: {
                    title: 'Example Title',
                    description: 'Example description for the link card.',
                    image: sampleImageUrl,
                    domain: 'example.com',
                    favicon: ''
                }
            }
        }
    } );

} )();
