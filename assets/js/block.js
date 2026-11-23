/**
 * "Form" block. Pick an existing form or build one right in the editor.
 *
 * The block only stores the form ID; the form itself is a separate, reusable lf_form record
 * edited through core-data. Changes made here are saved with the page (the editor lists the
 * form in its "save" panel) and appear everywhere the form is used.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var RawHTML = wp.element.RawHTML;
	var __ = wp.i18n.__;
	var c = wp.components;
	var be = wp.blockEditor;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useEntityProp = wp.coreData.useEntityProp;
	var ServerSideRender = wp.serverSideRender;
	var builder = window.launchFormsBuilder;
	var META = 'launch_forms_config';
	var TYPE = 'lf_form';

	function starterConfig() {
		var defaults = builder.data.defaults || {};
		var labels = builder.data.fieldTypes || {};
		var fields = [];
		var add = function ( type, label, required ) {
			var f = builder.createField( type, fields, labels );
			f.label = label;
			f.name = builder.uniqueName( builder.slugify( label ), fields, -1 );
			f.required = required;
			fields.push( f );
		};
		add( 'text', __( 'Name', 'launch-forms' ), true );
		add( 'email', __( 'Email', 'launch-forms' ), true );
		add( 'textarea', __( 'Message', 'launch-forms' ), true );
		var config = builder.normalizeConfig( { fields: fields }, defaults );
		if ( config.notifications[ 0 ] ) {
			config.notifications[ 0 ] = Object.assign( {}, config.notifications[ 0 ], { reply_to: '{field:email}' } );
		}
		return config;
	}

	/** Builder bound to the lf_form entity. */
	function FormEditor( props ) {
		var metaProp = useEntityProp( 'postType', TYPE, 'meta', props.formId );
		var titleProp = useEntityProp( 'postType', TYPE, 'title', props.formId );
		var meta = metaProp[ 0 ];
		if ( ! meta ) {
			return el( c.Spinner );
		}
		return el(
			'div',
			{ className: 'lf-block-editor' },
			el( c.TextControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				label: __( 'Form name', 'launch-forms' ),
				value: typeof titleProp[ 0 ] === 'string' ? titleProp[ 0 ] : '',
				onChange: titleProp[ 1 ],
			} ),
			el(
				c.Notice,
				{ status: 'info', isDismissible: false },
				__( 'This is a reusable form. Changes are saved when you save this page and apply everywhere the form is used.', 'launch-forms' )
			),
			el( builder.Builder, {
				config: builder.parseConfig( meta[ META ], builder.data.defaults ),
				onChange: function ( next ) {
					var nextMeta = Object.assign( {}, meta );
					nextMeta[ META ] = JSON.stringify( next );
					metaProp[ 1 ]( nextMeta );
				},
			} )
		);
	}

	/**
	 * The form as it will look, including changes not saved yet. The server renders the
	 * builder's current (edited) configuration; ServerSideRender would show the saved form.
	 */
	function Preview( props ) {
		var formId = props.formId;
		var info = useSelect(
			function ( select ) {
				var store = select( 'core' );
				var args = [ 'postType', TYPE, formId ];
				return {
					record: store.getEditedEntityRecord.apply( null, args ),
					resolved: store.hasFinishedResolution( 'getEntityRecord', args ),
				};
			},
			[ formId ]
		);
		var config = info.record && info.record.meta ? info.record.meta[ META ] : undefined;
		var state = useState( { html: null, error: '' } );
		var setState = state[ 1 ];

		useEffect(
			function () {
				if ( typeof config !== 'string' ) {
					return undefined;
				}
				var cancelled = false;
				var timer = setTimeout( function () {
					wp.apiFetch( {
						path: '/launch-forms/v1/forms/' + formId + '/preview',
						method: 'POST',
						data: { config: config, className: props.className || '' },
					} )
						.then( function ( response ) {
							if ( ! cancelled ) {
								setState( { html: response.html, error: '' } );
							}
						} )
						.catch( function ( err ) {
							if ( ! cancelled ) {
								setState( { html: null, error: ( err && err.message ) || __( 'Could not load the preview.', 'launch-forms' ) } );
							}
						} );
				}, 250 );
				return function () {
					cancelled = true;
					clearTimeout( timer );
				};
			},
			[ formId, config, props.className ]
		);

		// Form missing or not readable: let the server explain.
		if ( info.resolved && ! info.record ) {
			return el( c.Disabled, null, el( ServerSideRender, { block: 'launch-forms/form', attributes: { formId: formId } } ) );
		}
		if ( state[ 0 ].error ) {
			return el( c.Notice, { status: 'error', isDismissible: false }, state[ 0 ].error );
		}
		if ( state[ 0 ].html === null ) {
			return el( c.Spinner );
		}
		return el( c.Disabled, null, el( RawHTML, null, state[ 0 ].html ) );
	}

	function Edit( props ) {
		var formId = props.attributes.formId;
		var setAttributes = props.setAttributes;
		var blockProps = be.useBlockProps();
		var modeState = useState( formId ? 'preview' : 'pick' );
		var mode = formId ? modeState[ 0 ] : 'pick';
		var setMode = modeState[ 1 ];
		var creatingState = useState( false );
		var errorState = useState( '' );
		var saveEntityRecord = useDispatch( 'core' ).saveEntityRecord;

		var forms = useSelect( function ( select ) {
			return select( 'core' ).getEntityRecords( 'postType', TYPE, { per_page: 100, status: 'publish', orderby: 'title', order: 'asc' } );
		}, [] );

		var formOptions = [ { label: __( '— Select a form —', 'launch-forms' ), value: 0 } ].concat(
			( forms || [] ).map( function ( f ) {
				return { label: ( f.title && f.title.raw ) || ( f.title && f.title.rendered ) || '#' + f.id, value: f.id };
			} )
		);

		function createForm() {
			creatingState[ 1 ]( true );
			errorState[ 1 ]( '' );
			var meta = {};
			meta[ META ] = JSON.stringify( starterConfig() );
			saveEntityRecord( 'postType', TYPE, { title: __( 'Contact form', 'launch-forms' ), status: 'publish', meta: meta }, { throwOnError: true } )
				.then( function ( record ) {
					creatingState[ 1 ]( false );
					if ( record && record.id ) {
						setAttributes( { formId: record.id } );
						setMode( 'edit' );
					}
				} )
				.catch( function ( err ) {
					creatingState[ 1 ]( false );
					errorState[ 1 ]( ( err && err.message ) || __( 'Could not create the form.', 'launch-forms' ) );
				} );
		}

		var picker = el(
			c.Placeholder,
			{
				icon: 'feedback',
				label: __( 'Form', 'launch-forms' ),
				instructions: __( 'Choose an existing form or build a new one here. Forms are reusable across pages.', 'launch-forms' ),
			},
			errorState[ 0 ] && el( c.Notice, { status: 'error', isDismissible: false }, errorState[ 0 ] ),
			forms === null
				? el( c.Spinner )
				: el(
						'div',
						{ className: 'lf-block-picker' },
						forms.length > 0 &&
							el( c.SelectControl, {
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true,
								label: __( 'Existing form', 'launch-forms' ),
								value: formId,
								options: formOptions,
								onChange: function ( v ) {
									setAttributes( { formId: parseInt( v, 10 ) || 0 } );
									setMode( 'preview' );
								},
							} ),
						el(
							c.Button,
							{ variant: 'primary', isBusy: creatingState[ 0 ], disabled: creatingState[ 0 ], onClick: createForm },
							__( 'Create new form', 'launch-forms' )
						)
				  )
		);

		var toolbar =
			formId > 0 &&
			el(
				be.BlockControls,
				null,
				el(
					c.ToolbarGroup,
					null,
					el( c.ToolbarButton, {
						icon: mode === 'edit' ? 'visibility' : 'edit',
						label: mode === 'edit' ? __( 'Preview form', 'launch-forms' ) : __( 'Edit form', 'launch-forms' ),
						text: mode === 'edit' ? __( 'Preview', 'launch-forms' ) : __( 'Edit form', 'launch-forms' ),
						onClick: function () {
							setMode( mode === 'edit' ? 'preview' : 'edit' );
						},
					} ),
					el( c.ToolbarButton, {
						icon: 'randomize',
						label: __( 'Use a different form', 'launch-forms' ),
						onClick: function () {
							setAttributes( { formId: 0 } );
							setMode( 'pick' );
						},
					} )
				)
			);

		var inspector = el(
			be.InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: __( 'Form', 'launch-forms' ) },
				forms &&
					el( c.SelectControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Form', 'launch-forms' ),
						value: formId,
						options: formOptions,
						onChange: function ( v ) {
							setAttributes( { formId: parseInt( v, 10 ) || 0 } );
						},
					} ),
				formId > 0 &&
					el(
						Fragment,
						null,
						el( 'p', null, __( 'Shortcode for other builders:', 'launch-forms' ) ),
						el( 'code', null, '[launch_form id="' + formId + '"]' ),
						el(
							'p',
							null,
							el( 'a', { href: 'post.php?post=' + formId + '&action=edit', target: '_blank', rel: 'noreferrer' }, __( 'Open in the Forms screen', 'launch-forms' ) )
						)
					)
			)
		);

		var body;
		if ( mode === 'pick' ) {
			body = picker;
		} else if ( mode === 'edit' ) {
			body = el( FormEditor, { formId: formId } );
		} else {
			body = el( Preview, { formId: formId, className: props.attributes.className } );
		}

		return el( 'div', blockProps, toolbar, inspector, body );
	}

	wp.blocks.registerBlockType( 'launch-forms/form', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
