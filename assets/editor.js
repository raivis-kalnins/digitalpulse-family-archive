/* My Family Tree dynamic block. No family information is exposed in previews. */
(function (blocks, element, blockEditor, components, i18n) {
    'use strict';
    var el = element.createElement;
    var __ = i18n.__;
    blocks.registerBlockType('wp-my-family-tree/tree', {
        edit: function (props) {
            var blockProps = blockEditor.useBlockProps({className: 'wpmft-block-placeholder'});
            return el(element.Fragment, null,
                el(blockEditor.InspectorControls, null,
                    el(components.PanelBody, {title: __('Family tree', 'my-family-tree'), initialOpen: true},
                        el(components.TextControl, {
                            label: __('Starting person — post ID', 'my-family-tree'),
                            help: __('Leave 0 to use the starting person from Family Tree settings.', 'my-family-tree'),
                            type: 'number', min: 0,
                            value: props.attributes.rootId || 0,
                            onChange: function (value) {
                                props.setAttributes({rootId: Math.max(0, Math.floor(Number(value) || 0))});
                            }
                        })
                    )
                ),
                el('div', blockProps,
                    el('svg', {viewBox: '0 0 48 48', fill: 'none', 'aria-hidden': true},
                        el('path', {d: 'M23 43V27M14 43h19M24 31l-8-8M24 33l8-8', stroke: '#69523b', strokeWidth: 3, strokeLinecap: 'round'}),
                        el('path', {d: 'M24 5c-7-1-10 4-9 8-8-2-13 6-9 12 2 4 8 5 13 2 3 6 11 6 15 2 8 1 13-4 10-11-1-3-5-5-9-4 1-5-4-10-11-9Z', fill: '#426a45'})
                    ),
                    el('h3', null, __('Our family tree', 'my-family-tree')),
                    el('p', null, __('Your interactive oak tree appears here on the page. People open in dialogs and relatives load as visitors explore.', 'my-family-tree')),
                    el('p', null, __('Set the family category, password, languages, colours and contact details in Family Tree → Settings & help.', 'my-family-tree')),
                    el('code', null, props.attributes.rootId ? '[wp_my_family_tree root="' + props.attributes.rootId + '"]' : '[wp_my_family_tree]')
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
