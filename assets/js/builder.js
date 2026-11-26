/**
 * Launch Interactive Forms builder UI.
 *
 * One React component shared by the Forms admin screen (assets/js/admin.js) and the
 * Form block in the block editor (assets/js/block.js). Written with createElement so the
 * plugin ships without a build step.
 *
 * Pure helpers are exported (module.exports) for unit tests.
 */
( function ( root, factory ) {
	var helpers = factory.helpers();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = helpers;
		return;
	}
	root.liformsBuilder = factory.ui( helpers, root.wp, root.liformsBuilderData || {} );
} )( typeof self !== 'undefined' ? self : this, {
	/* ------------------------------------------------------------------ helpers */
	helpers: function () {
		'use strict';

		var OPTION_TYPES = [ 'select', 'radio', 'checkbox' ];
		var CAPTCHA_TYPES = [ 'recaptcha', 'turnstile' ];
		/** Types that show something but collect no value (mirrors Form_Config::NON_INPUT_TYPES). */
		var NON_INPUT_TYPES = [ 'content', 'recaptcha', 'turnstile' ];

		function isInput( field ) {
			return NON_INPUT_TYPES.indexOf( field.type ) === -1;
		}

		function isCaptcha( field ) {
			return CAPTCHA_TYPES.indexOf( field.type ) !== -1;
		}

		/** Text of a label that may contain HTML (consent labels can link to the terms). */
		function stripTags( value ) {
			return String( value || '' )
				.replace( /<[^>]*>/g, '' )
				.replace( /&nbsp;/g, ' ' )
				.replace( /&amp;/g, '&' )
				.replace( /&lt;/g, '<' )
				.replace( /&gt;/g, '>' )
				.replace( /&quot;/g, '"' )
				.replace( /&#0?39;/g, "'" )
				.trim();
		}

		function slugify( value ) {
			var slug = stripTags( value )
				.normalize( 'NFD' )
				.replace( /[̀-ͯ]/g, '' )
				.toLowerCase()
				.trim()
				.replace( /[^a-z0-9_]+/g, '_' )
				.replace( /^_+|_+$/g, '' );
			if ( slug && /^[0-9]/.test( slug ) ) {
				slug = 'f_' + slug;
			}
			return slug.slice( 0, 64 );
		}

		function uniqueName( base, fields, exceptIndex ) {
			base = base || 'field';
			var taken = fields
				.filter( function ( f, i ) {
					return i !== exceptIndex;
				} )
				.map( function ( f ) {
					return f.name;
				} );
			var candidate = base;
			var n = 2;
			while ( taken.indexOf( candidate ) !== -1 ) {
				candidate = base + '_' + n++;
			}
			return candidate;
		}

		function createField( type, fields, typeLabels ) {
			var label = ( typeLabels && typeLabels[ type ] ) || type;
			var field = {
				type: type,
				label: label,
				name: uniqueName( slugify( label ) || type, fields || [], -1 ),
				required: false,
				placeholder: '',
				description: '',
				default: '',
				width: 'full',
				css_class: '',
				conditions: { enabled: false, action: 'show', match: 'all', rules: [] },
			};
			if ( OPTION_TYPES.indexOf( type ) !== -1 ) {
				field.options = [
					{ label: 'Option 1', value: 'Option 1' },
					{ label: 'Option 2', value: 'Option 2' },
				];
			}
			if ( type === 'select' ) {
				field.multiple = false;
			}
			if ( type === 'number' ) {
				field.min = '';
				field.max = '';
				field.step = '';
			}
			if ( type === 'file' ) {
				field.allowed_types = [ 'jpg', 'jpeg', 'png', 'pdf' ];
				field.max_size = 5;
			}
			if ( type === 'content' ) {
				field.label = '';
				field.name = uniqueName( 'content', fields || [], -1 );
				field.content = '';
			}
			if ( CAPTCHA_TYPES.indexOf( type ) !== -1 ) {
				field.label = '';
				field.name = uniqueName( type, fields || [], -1 );
			}
			return field;
		}

		function optionsToText( options ) {
			return ( options || [] )
				.map( function ( o ) {
					return o.label === o.value ? o.label : o.label + '|' + o.value;
				} )
				.join( '\n' );
		}

		function textToOptions( text ) {
			return String( text || '' )
				.split( /\r?\n/ )
				.filter( function ( line ) {
					return line.trim() !== '';
				} )
				.map( function ( line ) {
					var parts = line.split( '|' );
					var label = parts[ 0 ].trim();
					var value = ( parts.length > 1 ? parts.slice( 1 ).join( '|' ) : parts[ 0 ] ).trim();
					return { label: label || value, value: value || label };
				} );
		}

		function moveItem( list, from, to ) {
			if ( to < 0 || to >= list.length ) {
				return list.slice();
			}
			var copy = list.slice();
			var item = copy.splice( from, 1 )[ 0 ];
			copy.splice( to, 0, item );
			return copy;
		}

		/**
		 * Where a dragged item lands, given its current top edge. Moving down, it passes each
		 * item whose midpoint its bottom edge has reached; moving up, each item whose midpoint
		 * its top edge has reached. (Using the item's centre instead meant it could never pass
		 * the last item when kept inside the list.) `rects` are { top, height } in one space.
		 */
		function dropIndex( rects, from, top ) {
			var start = rects[ from ].top;
			var bottom = top + rects[ from ].height;
			var to = from;
			var i;
			if ( top > start ) {
				for ( i = from + 1; i < rects.length && bottom >= rects[ i ].top + rects[ i ].height / 2; i++ ) {
					to = i;
				}
			} else if ( top < start ) {
				for ( i = from - 1; i >= 0 && top <= rects[ i ].top + rects[ i ].height / 2; i-- ) {
					to = i;
				}
			}
			return to;
		}

		/** The new position of item `index` after moveItem( list, from, to ). */
		function movedIndex( index, from, to ) {
			if ( index === from ) {
				return to;
			}
			if ( from < to && index > from && index <= to ) {
				return index - 1;
			}
			if ( from > to && index >= to && index < from ) {
				return index + 1;
			}
			return index;
		}

		/** Merge tags available for a config: general tags plus one per field. */
		function mergeTags( config ) {
			return [ '{all_fields}', '{form_title}', '{site_name}', '{admin_email}', '{page_url}', '{date}' ].concat(
				( config.fields || [] )
					.filter( isInput )
					.map( function ( f ) {
						return '{field:' + f.name + '}';
					} )
			);
		}

		/** Update every reference to a renamed field (conditions, Mailchimp, merge tags). */
		function renameReferences( config, oldName, newName ) {
			if ( ! oldName || oldName === newName ) {
				return config;
			}
			var tag = new RegExp( '\\{field:' + oldName.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\}', 'g' );
			var replaceTag = function ( text ) {
				return String( text || '' ).replace( tag, '{field:' + newName + '}' );
			};
			var mc = Object.assign( {}, config.mailchimp || {} );
			if ( mc.email_field === oldName ) {
				mc.email_field = newName;
			}
			if ( mc.consent_field === oldName ) {
				mc.consent_field = newName;
			}
			mc.merge_fields = ( mc.merge_fields || [] ).map( function ( m ) {
				return m.field === oldName ? Object.assign( {}, m, { field: newName } ) : m;
			} );
			var zoho = Object.assign( {}, config.zoho || {} );
			if ( zoho.consent_field === oldName ) {
				zoho.consent_field = newName;
			}
			zoho.field_map = ( zoho.field_map || [] ).map( function ( m ) {
				return m.field === oldName ? Object.assign( {}, m, { field: newName } ) : m;
			} );
			return Object.assign( {}, config, {
				fields: ( config.fields || [] ).map( function ( f ) {
					if ( ! f.conditions || ! f.conditions.rules ) {
						return f;
					}
					return Object.assign( {}, f, {
						conditions: Object.assign( {}, f.conditions, {
							rules: f.conditions.rules.map( function ( r ) {
								return r.field === oldName ? Object.assign( {}, r, { field: newName } ) : r;
							} ),
						} ),
					} );
				} ),
				notifications: ( config.notifications || [] ).map( function ( n ) {
					return Object.assign( {}, n, {
						to: replaceTag( n.to ),
						subject: replaceTag( n.subject ),
						message: replaceTag( n.message ),
						reply_to: replaceTag( n.reply_to ),
						from_name: replaceTag( n.from_name ),
					} );
				} ),
				mailchimp: mc,
				zoho: zoho,
				settings: Object.assign( {}, config.settings || {}, {
					success_message: replaceTag( ( config.settings || {} ).success_message ),
				} ),
			} );
		}

		/** Drop references to a removed field. */
		function removeReferences( config, name ) {
			var mc = Object.assign( {}, config.mailchimp || {} );
			if ( mc.email_field === name ) {
				mc.email_field = '';
			}
			// The consent field is kept on purpose: with it gone nobody is subscribed until
			// another field (or "Always subscribe") is chosen, instead of subscribing everyone.
			mc.merge_fields = ( mc.merge_fields || [] ).filter( function ( m ) {
				return m.field !== name;
			} );
			// As with Mailchimp, a removed Zoho consent field is kept so nothing is sent without it.
			var zoho = Object.assign( {}, config.zoho || {} );
			zoho.field_map = ( zoho.field_map || [] ).filter( function ( m ) {
				return m.field !== name;
			} );
			return Object.assign( {}, config, {
				fields: ( config.fields || [] ).map( function ( f ) {
					if ( ! f.conditions || ! f.conditions.rules ) {
						return f;
					}
					var rules = f.conditions.rules.filter( function ( r ) {
						return r.field !== name;
					} );
					return Object.assign( {}, f, {
						conditions: Object.assign( {}, f.conditions, { rules: rules, enabled: f.conditions.enabled && rules.length > 0 } ),
					} );
				} ),
				mailchimp: mc,
				zoho: zoho,
			} );
		}

		/** Zoho "field" that splits one Name field into First_Name and Last_Name (Zoho::FULL_NAME). */
		var ZOHO_FULL_NAME = '__full_name';

		/**
		 * A starting Zoho field map guessed from the form's fields (email → Email, a "name"
		 * field → First/Last name, and so on). The admin reviews it in the builder.
		 */
		function suggestZohoMap( fields ) {
			var rules = [
				[ /^(first_?name|fname|given_?name)$/, 'First_Name' ],
				[ /^(last_?name|lname|surname|family_?name)$/, 'Last_Name' ],
				[ /^(full_?)?name$/, ZOHO_FULL_NAME ],
				[ /^(company|organisation|organization|business)(_?name)?$/, 'Company' ],
				[ /^(mobile|cell)(_?phone)?$/, 'Mobile' ],
				[ /^(phone|telephone|tel)(_?number)?$/, 'Phone' ],
				[ /^(website|web|url|site)$/, 'Website' ],
				[ /^(message|enquiry|inquiry|comments?|details|description)$/, 'Description' ],
				[ /^(country)$/, 'Country' ],
				[ /^(city|town|suburb)$/, 'City' ],
				[ /^(state|region|province)$/, 'State' ],
				[ /^(postcode|post_?code|zip|zip_?code)$/, 'Zip_Code' ],
				[ /^(job_?title|title|position|role)$/, 'Designation' ],
			];
			var used = {};
			var map = [];
			var add = function ( zoho, field ) {
				if ( ! used[ zoho ] ) {
					used[ zoho ] = true;
					map.push( { zoho: zoho, type: '', field: field.name } );
				}
			};
			( fields || [] ).filter( isInput ).forEach( function ( f ) {
				if ( f.type === 'email' ) {
					add( 'Email', f );
					return;
				}
				if ( f.type === 'tel' && ! /mobile|cell/.test( f.name ) ) {
					add( 'Phone', f );
					return;
				}
				for ( var i = 0; i < rules.length; i++ ) {
					if ( rules[ i ][ 0 ].test( f.name ) ) {
						add( rules[ i ][ 1 ], f );
						return;
					}
				}
			} );
			// A full-name field isn't needed when first and last names have their own fields.
			if ( used.First_Name && used.Last_Name ) {
				map = map.filter( function ( m ) {
					return m.zoho !== ZOHO_FULL_NAME;
				} );
			}
			return map;
		}

		/** Required Zoho fields (from the field list) that nothing in the map fills. */
		function missingZohoFields( map, zohoFields ) {
			var mapped = {};
			( map || [] ).forEach( function ( m ) {
				if ( m.field ) {
					mapped[ m.zoho ] = true;
				}
			} );
			if ( mapped[ ZOHO_FULL_NAME ] ) {
				mapped.Last_Name = true;
			}
			return ( zohoFields || [] ).filter( function ( z ) {
				return z.required && ! mapped[ z.api_name ];
			} );
		}

		function normalizeConfig( config, defaults ) {
			defaults = defaults || {};
			config = config && typeof config === 'object' ? config : {};
			var fields = Array.isArray( config.fields ) ? config.fields : [];
			var settings = Object.assign( {}, defaults.settings || {}, config.settings || {} );
			// Older forms picked a captcha in settings; it is now a field (as the server does on save).
			if ( CAPTCHA_TYPES.indexOf( settings.captcha ) !== -1 && ! fields.some( isCaptcha ) ) {
				fields = fields.concat( [ createField( settings.captcha, fields, {} ) ] );
			}
			settings.captcha = 'none';
			return {
				fields: fields,
				settings: settings,
				notifications: Array.isArray( config.notifications ) ? config.notifications : ( defaults.notifications || [] ).slice(),
				mailchimp: Object.assign( {}, defaults.mailchimp || {}, config.mailchimp || {} ),
				zoho: Object.assign( {}, defaults.zoho || {}, config.zoho || {} ),
			};
		}

		function parseConfig( json, defaults ) {
			var data = null;
			try {
				data = typeof json === 'string' && json ? JSON.parse( json ) : json;
			} catch ( e ) {
				data = null;
			}
			return normalizeConfig( data, defaults );
		}

		return {
			OPTION_TYPES: OPTION_TYPES,
			CAPTCHA_TYPES: CAPTCHA_TYPES,
			NON_INPUT_TYPES: NON_INPUT_TYPES,
			isInput: isInput,
			isCaptcha: isCaptcha,
			slugify: slugify,
			stripTags: stripTags,
			uniqueName: uniqueName,
			createField: createField,
			optionsToText: optionsToText,
			textToOptions: textToOptions,
			moveItem: moveItem,
			mergeTags: mergeTags,
			dropIndex: dropIndex,
			movedIndex: movedIndex,
			renameReferences: renameReferences,
			removeReferences: removeReferences,
			ZOHO_FULL_NAME: ZOHO_FULL_NAME,
			suggestZohoMap: suggestZohoMap,
			missingZohoFields: missingZohoFields,
			normalizeConfig: normalizeConfig,
			parseConfig: parseConfig,
		};
	},

	/* ----------------------------------------------------------------------- UI */
	ui: function ( h, wp, data ) {
		'use strict';

		var el = wp.element.createElement;
		var Fragment = wp.element.Fragment;
		var useState = wp.element.useState;
		var useEffect = wp.element.useEffect;
		var useRef = wp.element.useRef;
		var __ = wp.i18n.__;
		var c = wp.components;

		var typeLabels = data.fieldTypes || {};
		var operators = data.operators || {};
		var WIDTH_BADGES = { half: '1/2', third: '1/3', 'two-thirds': '2/3', quarter: '1/4', 'three-quarters': '3/4' };
		var PLACEHOLDER_TYPES = [ 'text', 'email', 'url', 'tel', 'number', 'textarea', 'select', 'country' ];
		var countryOptions = ( data.countries || [] ).map( function ( name ) {
			return { label: name, value: name };
		} );

		/** Choices a field offers, for picking a value in conditional logic. */
		function choicesFor( field ) {
			if ( ! field ) {
				return [];
			}
			return field.type === 'country' ? countryOptions : field.options || [];
		}

		function set( obj, key, value ) {
			var copy = Object.assign( {}, obj );
			copy[ key ] = value;
			return copy;
		}

		function fieldChoices( fields, filter, emptyLabel ) {
			var choices = emptyLabel !== undefined ? [ { label: emptyLabel, value: '' } ] : [];
			return choices.concat(
				fields
					.filter( function ( f ) {
						return h.isInput( f ) && ( ! filter || filter( f ) );
					} )
					.map( function ( f ) {
						return { label: ( h.stripTags( f.label ) || f.name ) + ' (' + f.name + ')', value: f.name };
					} )
			);
		}

		/* ---------------- conditional logic */
		function ConditionsEditor( props ) {
			var field = props.field;
			var others = props.fields.filter( function ( f ) {
				return f.name !== field.name && h.isInput( f );
			} );
			var cond = field.conditions || { enabled: false, action: 'show', match: 'all', rules: [] };
			var update = function ( next ) {
				props.onChange( Object.assign( {}, cond, next ) );
			};

			if ( ! others.length ) {
				return el( 'p', { className: 'lif-builder__hint' }, __( 'Add another field to use conditional logic.', 'launch-interactive-forms' ) );
			}

			var rules = cond.rules || [];
			return el(
				'div',
				{ className: 'lif-builder__conditions' },
				el( c.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Conditional logic', 'launch-interactive-forms' ),
					checked: !! cond.enabled,
					onChange: function ( on ) {
						update( {
							enabled: on,
							rules: on && ! rules.length ? [ { field: others[ 0 ].name, operator: 'is', value: '' } ] : rules,
						} );
					},
				} ),
				cond.enabled &&
					el(
						Fragment,
						null,
						el(
							'div',
							{ className: 'lif-builder__row' },
							el( c.SelectControl, {
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true,
								label: __( 'Action', 'launch-interactive-forms' ),
								value: cond.action,
								options: [
									{ label: __( 'Show this field', 'launch-interactive-forms' ), value: 'show' },
									{ label: __( 'Hide this field', 'launch-interactive-forms' ), value: 'hide' },
								],
								onChange: function ( v ) {
									update( { action: v } );
								},
							} ),
							el( c.SelectControl, {
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true,
								label: __( 'When', 'launch-interactive-forms' ),
								value: cond.match,
								options: [
									{ label: __( 'all rules match', 'launch-interactive-forms' ), value: 'all' },
									{ label: __( 'any rule matches', 'launch-interactive-forms' ), value: 'any' },
								],
								onChange: function ( v ) {
									update( { match: v } );
								},
							} )
						),
						rules.map( function ( rule, i ) {
							var controller = props.fields.filter( function ( f ) {
								return f.name === rule.field;
							} )[ 0 ];
							var setRule = function ( key, value ) {
								var next = rules.slice();
								next[ i ] = set( rule, key, value );
								update( { rules: next } );
							};
							var needsValue = rule.operator !== 'empty' && rule.operator !== 'not_empty';
							var valueControl = null;
							if ( needsValue ) {
								valueControl =
									choicesFor( controller ).length
										? el( c.SelectControl, {
												__nextHasNoMarginBottom: true,
												__next40pxDefaultSize: true,
												label: __( 'Value', 'launch-interactive-forms' ),
												value: rule.value,
												options: [ { label: '—', value: '' } ].concat(
													choicesFor( controller ).map( function ( o ) {
														return { label: o.label, value: o.value };
													} )
												),
												onChange: function ( v ) {
													setRule( 'value', v );
												},
										  } )
										: el( c.TextControl, {
												__nextHasNoMarginBottom: true,
												__next40pxDefaultSize: true,
												label: __( 'Value', 'launch-interactive-forms' ),
												value: rule.value,
												onChange: function ( v ) {
													setRule( 'value', v );
												},
										  } );
							}
							return el(
								'div',
								{ className: 'lif-builder__row lif-builder__rule', key: i },
								el( c.SelectControl, {
									__nextHasNoMarginBottom: true,
									__next40pxDefaultSize: true,
									label: __( 'Field', 'launch-interactive-forms' ),
									value: rule.field,
									options: fieldChoices( others ),
									onChange: function ( v ) {
										setRule( 'field', v );
									},
								} ),
								el( c.SelectControl, {
									__nextHasNoMarginBottom: true,
									__next40pxDefaultSize: true,
									label: __( 'Operator', 'launch-interactive-forms' ),
									value: rule.operator,
									options: Object.keys( operators ).map( function ( k ) {
										return { label: operators[ k ], value: k };
									} ),
									onChange: function ( v ) {
										setRule( 'operator', v );
									},
								} ),
								valueControl,
								el( c.Button, {
									icon: 'no-alt',
									label: __( 'Remove rule', 'launch-interactive-forms' ),
									isDestructive: true,
									onClick: function () {
										var next = rules.filter( function ( r, j ) {
											return j !== i;
										} );
										update( { rules: next, enabled: next.length > 0 } );
									},
								} )
							);
						} ),
						el(
							c.Button,
							{
								variant: 'secondary',
								onClick: function () {
									update( { rules: rules.concat( [ { field: others[ 0 ].name, operator: 'is', value: '' } ] ) } );
								},
							},
							__( 'Add rule', 'launch-interactive-forms' )
						)
					)
			);
		}

		/* ---------------- one field */
		function FieldEditor( props ) {
			var field = props.field;
			var type = field.type;
			var noInput = ! h.isInput( field );
			var captcha = h.isCaptcha( field );
			var change = function ( key, value ) {
				props.onChange( set( field, key, value ) );
			};
			var text = function ( key, label, help ) {
				return el( c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: label,
					help: help,
					value: field[ key ] === undefined || field[ key ] === null ? '' : String( field[ key ] ),
					onChange: function ( v ) {
						change( key, v );
					},
				} );
			};

			var parts = [];
			if ( captcha ) {
				var configured = !! ( data.captcha && data.captcha[ type ] );
				parts.push(
					el(
						c.Notice,
						{ key: 'captcha', status: configured ? 'info' : 'warning', isDismissible: false },
						configured
							? __( 'The spam check appears here in the form. It is always shown and checked on the server, so it cannot use conditional logic.', 'launch-interactive-forms' )
							: __( 'Add the site and secret keys in Forms → Settings. Until then this field is not shown and the form is submitted without this check.', 'launch-interactive-forms' ),
						! configured && data.settingsUrl ? el( Fragment, null, ' ', el( 'a', { href: data.settingsUrl, target: '_blank', rel: 'noreferrer' }, __( 'Open settings', 'launch-interactive-forms' ) ) ) : null
					)
				);
				if ( type === 'recaptcha' && data.recaptchaVersion === 'v3' ) {
					parts.push( el( 'p', { key: 'v3', className: 'lif-builder__hint' }, __( 'reCAPTCHA v3 is invisible, so its position only matters for where errors appear. Google shows its badge in the corner of the page.', 'launch-interactive-forms' ) ) );
				}
			}
			if ( ! noInput ) {
				parts.push(
					el(
						'div',
						{ className: 'lif-builder__row', key: 'basic' },
						el( c.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Label', 'launch-interactive-forms' ),
							help:
								type === 'consent'
									? __( 'Links allowed, e.g. I agree to the <a href="/terms">terms and conditions</a>. Add target="_blank" to open in a new tab.', 'launch-interactive-forms' )
									: undefined,
							value: field.label,
							onChange: props.onLabelChange,
						} ),
						el( c.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Field key', 'launch-interactive-forms' ),
							help: __( 'Used in merge tags, e.g. {field:key}', 'launch-interactive-forms' ),
							value: field.name,
							onChange: props.onNameChange,
						} )
					)
				);
			}

			if ( type === 'content' ) {
				parts.push(
					el( c.TextareaControl, {
						key: 'content',
						__nextHasNoMarginBottom: true,
						label: __( 'Content (HTML allowed)', 'launch-interactive-forms' ),
						rows: 4,
						value: field.content || '',
						onChange: function ( v ) {
							change( 'content', v );
						},
					} )
				);
			}

			if ( h.OPTION_TYPES.indexOf( type ) !== -1 ) {
				parts.push(
					el( c.TextareaControl, {
						key: 'options',
						__nextHasNoMarginBottom: true,
						label: __( 'Choices', 'launch-interactive-forms' ),
						help: __( 'One per line. Use "Label|value" to store a different value.', 'launch-interactive-forms' ),
						rows: 4,
						value: props.optionsText,
						onChange: props.onOptionsChange,
					} )
				);
			}

			var row = [];
			if ( PLACEHOLDER_TYPES.indexOf( type ) !== -1 ) {
				row.push( el( 'div', { key: 'ph' }, text( 'placeholder', __( 'Placeholder', 'launch-interactive-forms' ) ) ) );
			}
			if ( type === 'country' ) {
				row.push(
					el( c.SelectControl, {
						key: 'def',
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Default country', 'launch-interactive-forms' ),
						value: field.default || '',
						options: [ { label: __( 'None', 'launch-interactive-forms' ), value: '' } ].concat( countryOptions ),
						onChange: function ( v ) {
							change( 'default', v );
						},
					} )
				);
			} else if ( type !== 'file' && ! noInput ) {
				row.push(
					el(
						'div',
						{ key: 'def' },
						text(
							'default',
							type === 'hidden' ? __( 'Value', 'launch-interactive-forms' ) : __( 'Default value', 'launch-interactive-forms' ),
							type === 'checkbox' ? __( 'Comma separated values', 'launch-interactive-forms' ) : undefined
						)
					)
				);
			}
			if ( row.length ) {
				parts.push( el( 'div', { className: 'lif-builder__row', key: 'row' }, row ) );
			}

			if ( type === 'number' ) {
				parts.push(
					el(
						'div',
						{ className: 'lif-builder__row', key: 'num' },
						text( 'min', __( 'Minimum', 'launch-interactive-forms' ) ),
						text( 'max', __( 'Maximum', 'launch-interactive-forms' ) ),
						text( 'step', __( 'Step', 'launch-interactive-forms' ) )
					)
				);
			}

			if ( type === 'file' ) {
				parts.push(
					el(
						'div',
						{ className: 'lif-builder__row', key: 'file' },
						el( c.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Allowed file types', 'launch-interactive-forms' ),
							help: __( 'Comma separated extensions. Scripts and executables are always blocked.', 'launch-interactive-forms' ),
							value: props.fileTypesText,
							onChange: props.onFileTypesChange,
						} ),
						el( c.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							type: 'number',
							min: 0.1,
							step: 0.1,
							label: __( 'Max size (MB)', 'launch-interactive-forms' ),
							value: String( field.max_size ),
							onChange: function ( v ) {
								change( 'max_size', v );
							},
						} )
					)
				);
			}

			if ( ! noInput && type !== 'hidden' ) {
				parts.push( el( 'div', { key: 'desc' }, text( 'description', __( 'Help text', 'launch-interactive-forms' ) ) ) );
			}

			var toggles = [];
			if ( ! noInput && type !== 'hidden' ) {
				toggles.push(
					el( c.ToggleControl, {
						key: 'req',
						__nextHasNoMarginBottom: true,
						label: __( 'Required', 'launch-interactive-forms' ),
						checked: !! field.required,
						onChange: function ( v ) {
							change( 'required', v );
						},
					} )
				);
			}
			if ( type === 'select' ) {
				toggles.push(
					el( c.ToggleControl, {
						key: 'multi',
						__nextHasNoMarginBottom: true,
						label: __( 'Allow multiple selections', 'launch-interactive-forms' ),
						checked: !! field.multiple,
						onChange: function ( v ) {
							change( 'multiple', v );
						},
					} )
				);
			}
			if ( toggles.length ) {
				parts.push( el( 'div', { className: 'lif-builder__row', key: 'toggles' }, toggles ) );
			}

			var widths = data.widths || {};
			parts.push(
				el(
					'div',
					{ className: 'lif-builder__row', key: 'css' },
					type !== 'hidden' &&
						el( c.SelectControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Width', 'launch-interactive-forms' ),
							help: __( 'Fields narrower than full width sit side by side. They stack on small screens.', 'launch-interactive-forms' ),
							value: field.width || 'full',
							options: Object.keys( widths ).map( function ( k ) {
								return { label: widths[ k ], value: k };
							} ),
							onChange: function ( v ) {
								change( 'width', v );
							},
						} ),
					text( 'css_class', __( 'CSS class(es)', 'launch-interactive-forms' ) )
				)
			);

			if ( type !== 'hidden' && ! captcha ) {
				parts.push(
					el( ConditionsEditor, {
						key: 'cond',
						field: field,
						fields: props.fields,
						onChange: function ( cond ) {
							change( 'conditions', cond );
						},
					} )
				);
			}

			return el( 'div', { className: 'lif-builder__field-body' }, parts );
		}

		/* ---------------- drag and drop */

		/** Nearest scrolling ancestor, or the document's scrolling element. */
		function scrollParent( node ) {
			var doc = node.ownerDocument;
			for ( var p = node.parentElement; p && p !== doc.body; p = p.parentElement ) {
				var overflow = doc.defaultView.getComputedStyle( p ).overflowY;
				if ( ( overflow === 'auto' || overflow === 'scroll' ) && p.scrollHeight > p.clientHeight ) {
					return p;
				}
			}
			return doc.scrollingElement || doc.documentElement;
		}

		/**
		 * Pointer-driven reordering for the field list. Uses pointer events rather than
		 * HTML5 drag and drop so it works on touch screens and the block editor's own
		 * drop zones never see the drag. Keyboard users focus the handle and press the
		 * up and down arrow keys.
		 */
		function useSortable( itemClass, onMove ) {
			var listRef = useRef( null );
			var dragRef = useRef( null );
			var stateHook = useState( null );
			var setState = stateHook[ 1 ];

			function items() {
				var list = listRef.current;
				return list
					? Array.prototype.filter.call( list.children, function ( n ) {
							return n.classList.contains( itemClass );
					  } )
					: [];
			}

			function update() {
				var d = dragRef.current;
				var listTop = listRef.current.getBoundingClientRect().top;
				var dy = d.clientY - listTop - d.startY;
				var from = d.from;
				var top = d.rects[ from ].top;
				var last = d.rects[ d.rects.length - 1 ];
				// Keep the dragged item within the list.
				dy = Math.max( -top, Math.min( dy, last.top + last.height - d.rects[ from ].height - top ) );
				var to = h.dropIndex( d.rects, from, top + dy );
				setState( { from: from, to: to, dy: dy, step: d.step } );
				d.to = to;
			}

			function tick() {
				var d = dragRef.current;
				if ( ! d ) {
					return;
				}
				var s = d.scroller;
				var win = s.ownerDocument.defaultView;
				var isDoc = s === s.ownerDocument.scrollingElement || s === s.ownerDocument.documentElement;
				var bounds = isDoc ? { top: 0, bottom: win.innerHeight } : s.getBoundingClientRect();
				var edge = 48;
				var speed = 0;
				if ( d.clientY < bounds.top + edge ) {
					speed = -Math.ceil( ( bounds.top + edge - d.clientY ) / 4 );
				} else if ( d.clientY > bounds.bottom - edge ) {
					speed = Math.ceil( ( d.clientY - ( bounds.bottom - edge ) ) / 4 );
				}
				if ( speed ) {
					var before = s.scrollTop;
					s.scrollTop += speed;
					if ( s.scrollTop !== before ) {
						update();
					}
				}
				d.frame = win.requestAnimationFrame( tick );
			}

			function finish( commit ) {
				var d = dragRef.current;
				if ( ! d ) {
					return;
				}
				dragRef.current = null;
				d.handle.removeEventListener( 'pointermove', d.onMoveEvent );
				d.handle.removeEventListener( 'pointerup', d.onUp );
				d.handle.removeEventListener( 'pointercancel', d.onCancel );
				d.handle.ownerDocument.removeEventListener( 'keydown', d.onKey, true );
				d.handle.ownerDocument.defaultView.cancelAnimationFrame( d.frame );
				setState( null );
				if ( commit && d.to !== d.from ) {
					onMove( d.from, d.to );
				}
			}

			function start( event, index ) {
				if ( event.button !== 0 || dragRef.current ) {
					return;
				}
				event.preventDefault();
				var handle = event.currentTarget;
				var nodes = items();
				var listTop = listRef.current.getBoundingClientRect().top;
				var rects = nodes.map( function ( n ) {
					var r = n.getBoundingClientRect();
					return { top: r.top - listTop, height: r.height };
				} );
				if ( rects.length < 2 ) {
					return;
				}
				var gap = rects[ 1 ].top - rects[ 0 ].top - rects[ 0 ].height;
				var d = {
					from: index,
					to: index,
					rects: rects,
					step: rects[ index ].height + gap,
					startY: event.clientY - listTop,
					clientY: event.clientY,
					handle: handle,
					scroller: scrollParent( listRef.current ),
				};
				d.onMoveEvent = function ( e ) {
					d.clientY = e.clientY;
					update();
				};
				d.onUp = function () {
					finish( true );
				};
				d.onCancel = function () {
					finish( false );
				};
				d.onKey = function ( e ) {
					if ( e.key === 'Escape' ) {
						e.preventDefault();
						e.stopPropagation();
						finish( false );
					}
				};
				dragRef.current = d;
				try {
					handle.setPointerCapture( event.pointerId );
				} catch ( e ) {}
				handle.addEventListener( 'pointermove', d.onMoveEvent );
				handle.addEventListener( 'pointerup', d.onUp );
				handle.addEventListener( 'pointercancel', d.onCancel );
				handle.ownerDocument.addEventListener( 'keydown', d.onKey, true );
				d.frame = handle.ownerDocument.defaultView.requestAnimationFrame( tick );
				setState( { from: index, to: index, dy: 0, step: d.step } );
			}

			// Abandon a drag if the list unmounts mid-way.
			useEffect( function () {
				return function () {
					finish( false );
				};
			}, [] );

			/** Inline style for item `index` while a drag is in progress. */
			function itemStyle( index ) {
				var s = stateHook[ 0 ];
				if ( ! s ) {
					return undefined;
				}
				var y = 0;
				if ( index === s.from ) {
					y = s.dy;
				} else if ( s.from < s.to && index > s.from && index <= s.to ) {
					y = -s.step;
				} else if ( s.from > s.to && index >= s.to && index < s.from ) {
					y = s.step;
				}
				return y ? { transform: 'translateY(' + y + 'px)' } : undefined;
			}

			return { listRef: listRef, drag: stateHook[ 0 ], start: start, itemStyle: itemStyle };
		}

		/** Six-dot drag handle icon (same glyph as the block editor's). */
		var dragIcon = el(
			'svg',
			{ viewBox: '0 0 24 24', width: 24, height: 24, 'aria-hidden': true, focusable: false },
			el( 'path', { d: 'M8 7h2V5H8v2zm0 6h2v-2H8v2zm0 6h2v-2H8v2zm6-14v2h2V5h-2zm0 8h2v-2h-2v2zm0 6h2v-2h-2v2z' } )
		);

		/* ---------------- fields tab */
		function FieldsPanel( props ) {
			var config = props.config;
			var fields = config.fields;
			var openState = useState( -1 );
			var open = openState[ 0 ];
			var setOpen = openState[ 1 ];
			var addType = useState( 'text' );
			// Keep raw textarea text so typing "|" or blank lines isn't fought by re-serialisation.
			var drafts = useState( {} );

			var setFields = function ( next, extra ) {
				props.onChange( Object.assign( {}, extra || config, { fields: next } ) );
			};
			var updateField = function ( index, field ) {
				var next = fields.slice();
				next[ index ] = field;
				setFields( next );
			};
			var rename = function ( index, newName, newLabel ) {
				var old = fields[ index ];
				var name = h.uniqueName( h.slugify( newName ) || old.type, fields, index );
				var updated = h.renameReferences( config, old.name, name );
				var next = updated.fields.slice();
				next[ index ] = Object.assign( {}, old, { name: name }, newLabel !== undefined ? { label: newLabel } : {} );
				setFields( next, updated );
			};

			var draftKey = function ( index, key ) {
				return index + ':' + key;
			};
			var move = function ( from, to ) {
				if ( to < 0 || to >= fields.length || to === from ) {
					return;
				}
				setFields( h.moveItem( fields, from, to ) );
				if ( open !== -1 ) {
					setOpen( h.movedIndex( open, from, to ) );
				}
				drafts[ 1 ]( {} );
			};
			var sortable = useSortable( 'lif-builder__field', move );
			var dragging = sortable.drag;

			// Keep keyboard focus on the handle of the field that was just moved.
			var refocus = useRef( null );
			useEffect( function () {
				var to = refocus.current;
				var list = sortable.listRef.current;
				refocus.current = null;
				if ( to === null || ! list ) {
					return;
				}
				var item = list.querySelectorAll( '.lif-builder__field' )[ to ];
				var handle = item && item.querySelector( '.lif-builder__handle' );
				if ( handle ) {
					handle.focus();
				}
			} );

			return el(
				'div',
				{ className: 'lif-builder__fields' + ( dragging ? ' is-sorting' : '' ), ref: sortable.listRef },
				! fields.length && el( 'p', { className: 'lif-builder__hint' }, __( 'No fields yet. Add your first field below.', 'launch-interactive-forms' ) ),
				fields.map( function ( field, index ) {
					var isOpen = open === index;
					var title = field.type === 'content' ? __( 'Content', 'launch-interactive-forms' ) : h.isCaptcha( field ) ? typeLabels[ field.type ] || field.type : h.stripTags( field.label ) || field.name;
					return el(
						'div',
						{
							className: 'lif-builder__field' + ( isOpen ? ' is-open' : '' ) + ( dragging && dragging.from === index ? ' is-dragging' : '' ),
							key: index,
							style: sortable.itemStyle( index ),
						},
						el(
							'div',
							{ className: 'lif-builder__field-head' },
							fields.length > 1 &&
								el(
									'span',
									{
										className: 'lif-builder__handle',
										role: 'button',
										tabIndex: 0,
										title: __( 'Drag to reorder', 'launch-interactive-forms' ),
										'aria-label': wp.i18n.sprintf(
											/* translators: %s: field name. */
											__( 'Reorder %s. Use the up and down arrow keys to move it.', 'launch-interactive-forms' ),
											title
										),
										onPointerDown: function ( e ) {
											sortable.start( e, index );
										},
										onKeyDown: function ( e ) {
											var step = { ArrowUp: -1, ArrowDown: 1 }[ e.key ];
											if ( ! step || e.altKey || e.ctrlKey || e.metaKey ) {
												return;
											}
											e.preventDefault();
											var to = index + step;
											if ( to < 0 || to >= fields.length ) {
												return;
											}
											move( index, to );
											refocus.current = to;
											if ( wp.a11y && wp.a11y.speak ) {
												wp.a11y.speak(
													wp.i18n.sprintf(
														/* translators: 1: field name, 2: new position, 3: number of fields. */
														__( '%1$s moved to position %2$d of %3$d.', 'launch-interactive-forms' ),
														title,
														to + 1,
														fields.length
													)
												);
											}
										},
									},
									dragIcon
								),
							el(
								'button',
								{
									type: 'button',
									className: 'lif-builder__field-title',
									'aria-expanded': isOpen,
									onClick: function () {
										setOpen( isOpen ? -1 : index );
									},
								},
								el( 'strong', null, title ),
								field.required ? ' *' : '',
								el( 'span', { className: 'lif-builder__meta' }, h.isCaptcha( field ) ? __( 'Spam check', 'launch-interactive-forms' ) : ( typeLabels[ field.type ] || field.type ) + ( h.isInput( field ) ? ' · ' + field.name : '' ) ),
								WIDTH_BADGES[ field.width ] ? el( 'span', { className: 'lif-builder__badge', title: ( data.widths || {} )[ field.width ] }, WIDTH_BADGES[ field.width ] ) : null,
								field.conditions && field.conditions.enabled ? el( 'span', { className: 'lif-builder__badge' }, __( 'conditional', 'launch-interactive-forms' ) ) : null
							),
							el( c.Button, {
								icon: 'admin-page',
								label: __( 'Duplicate', 'launch-interactive-forms' ),
								onClick: function () {
									var copy = JSON.parse( JSON.stringify( field ) );
									copy.name = h.uniqueName( field.name, fields, -1 );
									var next = fields.slice();
									next.splice( index + 1, 0, copy );
									setFields( next );
									setOpen( index + 1 );
									drafts[ 1 ]( {} );
								},
							} ),
							el( c.Button, {
								icon: 'trash',
								label: __( 'Delete', 'launch-interactive-forms' ),
								isDestructive: true,
								onClick: function () {
									// eslint-disable-next-line no-alert
									if ( ! window.confirm( __( 'Delete this field?', 'launch-interactive-forms' ) ) ) {
										return;
									}
									var cleaned = h.removeReferences( config, field.name );
									setFields(
										cleaned.fields.filter( function ( f, i ) {
											return i !== index;
										} ),
										cleaned
									);
									setOpen( -1 );
									drafts[ 1 ]( {} );
								},
							} )
						),
						isOpen &&
							el( FieldEditor, {
								field: field,
								fields: fields,
								onChange: function ( f ) {
									updateField( index, f );
								},
								onLabelChange: function ( label ) {
									var auto = field.name === h.uniqueName( h.slugify( field.label ) || field.type, fields, index );
									if ( auto ) {
										rename( index, h.slugify( label ) || field.type, label );
									} else {
										updateField( index, set( field, 'label', label ) );
									}
								},
								onNameChange: function ( name ) {
									rename( index, name );
								},
								optionsText:
									drafts[ 0 ][ draftKey( index, 'options' ) ] !== undefined
										? drafts[ 0 ][ draftKey( index, 'options' ) ]
										: h.optionsToText( field.options ),
								onOptionsChange: function ( text ) {
									drafts[ 1 ]( set( drafts[ 0 ], draftKey( index, 'options' ), text ) );
									updateField( index, set( field, 'options', h.textToOptions( text ) ) );
								},
								fileTypesText:
									drafts[ 0 ][ draftKey( index, 'types' ) ] !== undefined
										? drafts[ 0 ][ draftKey( index, 'types' ) ]
										: ( field.allowed_types || [] ).join( ', ' ),
								onFileTypesChange: function ( text ) {
									drafts[ 1 ]( set( drafts[ 0 ], draftKey( index, 'types' ), text ) );
									var blocked = data.blockedFileTypes || [];
									var types = text
										.split( ',' )
										.map( function ( t ) {
											return t.trim().replace( /^\./, '' ).toLowerCase();
										} )
										.filter( function ( t ) {
											return t && blocked.indexOf( t ) === -1;
										} );
									updateField( index, set( field, 'allowed_types', types ) );
								},
							} )
					);
				} ),
				el(
					'div',
					{ className: 'lif-builder__add' },
					el( c.SelectControl, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Field type', 'launch-interactive-forms' ),
						hideLabelFromVision: true,
						value: addType[ 0 ],
						options: Object.keys( typeLabels )
							.filter( function ( k ) {
								// One spam check per form.
								return h.CAPTCHA_TYPES.indexOf( k ) === -1 || ! fields.some( h.isCaptcha );
							} )
							.map( function ( k ) {
								var missing = h.CAPTCHA_TYPES.indexOf( k ) !== -1 && ! ( data.captcha && data.captcha[ k ] );
								return { label: typeLabels[ k ] + ( missing ? ' ' + __( '(keys not set)', 'launch-interactive-forms' ) : '' ), value: k };
							} ),
						onChange: addType[ 1 ],
					} ),
					el(
						c.Button,
						{
							variant: 'primary',
							onClick: function () {
								var type = h.CAPTCHA_TYPES.indexOf( addType[ 0 ] ) !== -1 && fields.some( h.isCaptcha ) ? 'text' : addType[ 0 ];
								setFields( fields.concat( [ h.createField( type, fields, typeLabels ) ] ) );
								if ( type !== addType[ 0 ] ) {
									addType[ 1 ]( 'text' );
								}
								setOpen( fields.length );
							},
						},
						__( 'Add field', 'launch-interactive-forms' )
					)
				)
			);
		}

		/* ---------------- settings tab */
		function SettingsPanel( props ) {
			var s = props.config.settings;
			var change = function ( key, value ) {
				props.onChange( Object.assign( {}, props.config, { settings: set( s, key, value ) } ) );
			};
			var captchaField = ( props.config.fields || [] ).filter( h.isCaptcha )[ 0 ];

			return el(
				'div',
				{ className: 'lif-builder__panel' },
				el( c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Submit button text', 'launch-interactive-forms' ),
					value: s.submit_label,
					onChange: function ( v ) {
						change( 'submit_label', v );
					},
				} ),
				el(
					'div',
					null,
					el( c.TextareaControl, {
						__nextHasNoMarginBottom: true,
						label: __( 'Success message', 'launch-interactive-forms' ),
						help: __( 'Basic HTML is allowed. Merge tags are replaced with what the visitor entered, e.g. "A confirmation email was sent to {field:email}."', 'launch-interactive-forms' ),
						rows: 4,
						value: s.success_message,
						onChange: function ( v ) {
							change( 'success_message', v );
						},
					} ),
					el(
						'p',
						{ className: 'lif-builder__hint' },
						__( 'Merge tags:', 'launch-interactive-forms' ),
						' ',
						h.mergeTags( props.config ).map( function ( t ) {
							return el( 'code', { key: t }, t );
						} )
					)
				),
				el( c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Redirect URL (optional)', 'launch-interactive-forms' ),
					help: __( 'Send people to this page after submitting instead of showing the message.', 'launch-interactive-forms' ),
					type: 'url',
					value: s.redirect_url,
					onChange: function ( v ) {
						change( 'redirect_url', v );
					},
				} ),
				el( c.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Hide the form after a successful submission', 'launch-interactive-forms' ),
					checked: !! s.hide_form_on_success,
					onChange: function ( v ) {
						change( 'hide_form_on_success', v );
					},
				} ),
				el( c.TextControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Google Analytics event (optional)', 'launch-interactive-forms' ),
					help: __( 'Event name sent to Google Analytics or Google Tag Manager when this form is submitted successfully, e.g. contact_form_submit or generate_lead. It includes form_id and form_name. Your site must already load Google Analytics. Leave empty to send nothing.', 'launch-interactive-forms' ),
					placeholder: 'contact_form_submit',
					value: s.analytics_event || '',
					onChange: function ( v ) {
						change( 'analytics_event', v );
					},
				} ),
				el( c.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Save submissions in WordPress', 'launch-interactive-forms' ),
					help: s.store_submissions ? null : __( 'Uploaded files are deleted right after notifications are sent.', 'launch-interactive-forms' ),
					checked: !! s.store_submissions,
					onChange: function ( v ) {
						change( 'store_submissions', v );
					},
				} ),
				el(
					'div',
					null,
					el( 'p', { className: 'lif-builder__label' }, __( 'Spam protection', 'launch-interactive-forms' ) ),
					el(
						'p',
						{ className: 'lif-builder__hint' },
						captchaField
							? wp.i18n.sprintf(
									/* translators: %s: Google reCAPTCHA or Cloudflare Turnstile. */
									__( 'Every form has a hidden honeypot. This form also uses %s; move or remove it on the Fields tab.', 'launch-interactive-forms' ),
									typeLabels[ captchaField.type ] || captchaField.type
							  )
							: __( 'Every form has a hidden honeypot. For stronger protection, add a Google reCAPTCHA or Cloudflare Turnstile field on the Fields tab and drag it where you want it.', 'launch-interactive-forms' )
					)
				)
			);
		}

		/* ---------------- notifications tab */
		function NotificationsPanel( props ) {
			var config = props.config;
			var list = config.notifications || [];
			var hasFiles = config.fields.some( function ( f ) {
				return f.type === 'file';
			} );
			var setList = function ( next ) {
				props.onChange( Object.assign( {}, config, { notifications: next } ) );
			};
			var tags = h.mergeTags( config );

			return el(
				'div',
				{ className: 'lif-builder__panel' },
				el( 'p', { className: 'lif-builder__hint' }, __( 'Merge tags:', 'launch-interactive-forms' ), ' ', tags.map( function ( t ) {
					return el( 'code', { key: t }, t );
				} ) ),
				list.map( function ( n, i ) {
					var change = function ( key, value ) {
						var next = list.slice();
						next[ i ] = set( n, key, value );
						setList( next );
					};
					var text = function ( key, label, help ) {
						return el( c.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: label,
							help: help,
							value: n[ key ] || '',
							onChange: function ( v ) {
								change( key, v );
							},
						} );
					};
					return el(
						'div',
						{ className: 'lif-builder__card', key: i },
						el(
							'div',
							{ className: 'lif-builder__row' },
							el( c.ToggleControl, {
								__nextHasNoMarginBottom: true,
								label: __( 'Enabled', 'launch-interactive-forms' ),
								checked: !! n.enabled,
								onChange: function ( v ) {
									change( 'enabled', v );
								},
							} ),
							el(
								c.Button,
								{
									isDestructive: true,
									variant: 'link',
									onClick: function () {
										setList(
											list.filter( function ( x, j ) {
												return j !== i;
											} )
										);
									},
								},
								__( 'Remove notification', 'launch-interactive-forms' )
							)
						),
						text( 'name', __( 'Name (for your reference)', 'launch-interactive-forms' ) ),
						text( 'to', __( 'Send to', 'launch-interactive-forms' ), __( 'Comma separated. Merge tags allowed, e.g. {field:email} for an autoresponder.', 'launch-interactive-forms' ) ),
						text( 'subject', __( 'Subject', 'launch-interactive-forms' ) ),
						el( c.TextareaControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Message', 'launch-interactive-forms' ),
							rows: 5,
							value: n.message || '',
							onChange: function ( v ) {
								change( 'message', v );
							},
						} ),
						el( 'div', { className: 'lif-builder__row' }, text( 'reply_to', __( 'Reply-To', 'launch-interactive-forms' ) ), text( 'from_name', __( 'From name', 'launch-interactive-forms' ) ), text( 'from_email', __( 'From email', 'launch-interactive-forms' ) ) ),
						hasFiles &&
							el( c.ToggleControl, {
								__nextHasNoMarginBottom: true,
								label: __( 'Attach uploaded files', 'launch-interactive-forms' ),
								checked: !! n.attach_files,
								onChange: function ( v ) {
									change( 'attach_files', v );
								},
							} )
					);
				} ),
				el(
					c.Button,
					{
						variant: 'secondary',
						onClick: function () {
							var template = ( data.defaults && data.defaults.notifications && data.defaults.notifications[ 0 ] ) || {};
							setList( list.concat( [ Object.assign( {}, template, { name: '' } ) ] ) );
						},
					},
					__( 'Add notification', 'launch-interactive-forms' )
				)
			);
		}

		/* ---------------- Mailchimp tab */
		function MailchimpPanel( props ) {
			var config = props.config;
			var mc = config.mailchimp;
			var consentMissing =
				!! mc.consent_field &&
				! ( config.fields || [] ).some( function ( f ) {
					return f.name === mc.consent_field;
				} );
			var listsState = useState( null );
			var errorState = useState( '' );
			var change = function ( key, value ) {
				props.onChange( Object.assign( {}, config, { mailchimp: set( mc, key, value ) } ) );
			};

			useEffect(
				function () {
					if ( ! data.mailchimpConfigured || ! mc.enabled || listsState[ 0 ] !== null || ! wp.apiFetch ) {
						return;
					}
					wp.apiFetch( { path: '/liforms/v1/mailchimp/lists' } ).then(
						function ( lists ) {
							listsState[ 1 ]( lists );
						},
						function ( err ) {
							listsState[ 1 ]( [] );
							errorState[ 1 ]( ( err && err.message ) || 'Error' );
						}
					);
				},
				[ mc.enabled ]
			);

			if ( ! data.mailchimpConfigured ) {
				return el(
					c.Notice,
					{ status: 'info', isDismissible: false },
					__( 'Add your Mailchimp API key in Forms → Settings to subscribe people when they submit this form.', 'launch-interactive-forms' ),
					data.settingsUrl ? el( Fragment, null, ' ', el( 'a', { href: data.settingsUrl, target: '_blank', rel: 'noreferrer' }, __( 'Open settings', 'launch-interactive-forms' ) ) ) : null
				);
			}

			var lists = listsState[ 0 ];
			var mergeFields = mc.merge_fields || [];
			return el(
				'div',
				{ className: 'lif-builder__panel' },
				el( c.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Subscribe submitters to Mailchimp', 'launch-interactive-forms' ),
					checked: !! mc.enabled,
					onChange: function ( v ) {
						change( 'enabled', v );
					},
				} ),
				mc.enabled &&
					el(
						Fragment,
						null,
						errorState[ 0 ] && el( c.Notice, { status: 'error', isDismissible: false }, errorState[ 0 ] ),
						lists && lists.length
							? el( c.SelectControl, {
									__nextHasNoMarginBottom: true,
									__next40pxDefaultSize: true,
									label: __( 'Audience', 'launch-interactive-forms' ),
									value: mc.list_id,
									options: [ { label: '—', value: '' } ].concat(
										lists.map( function ( l ) {
											return { label: l.name, value: l.id };
										} )
									),
									onChange: function ( v ) {
										change( 'list_id', v );
									},
							  } )
							: el( c.TextControl, {
									__nextHasNoMarginBottom: true,
									__next40pxDefaultSize: true,
									label: __( 'Audience ID', 'launch-interactive-forms' ),
									help: lists === null ? __( 'Loading audiences…', 'launch-interactive-forms' ) : undefined,
									value: mc.list_id,
									onChange: function ( v ) {
										change( 'list_id', v );
									},
							  } ),
						el( c.SelectControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Email field', 'launch-interactive-forms' ),
							value: mc.email_field,
							options: fieldChoices(
								config.fields,
								function ( f ) {
									return f.type === 'email' || f.type === 'text' || f.type === 'hidden';
								},
								'—'
							),
							onChange: function ( v ) {
								change( 'email_field', v );
							},
						} ),
						el( c.SelectControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Only subscribe when this field is ticked', 'launch-interactive-forms' ),
							value: mc.consent_field,
							options: fieldChoices(
								config.fields,
								function ( f ) {
									return f.type === 'consent' || f.type === 'checkbox';
								},
								__( 'Always subscribe', 'launch-interactive-forms' )
							).concat(
								consentMissing
									? [ { label: wp.i18n.sprintf( /* translators: %s: field name. */ __( 'Removed field (%s)', 'launch-interactive-forms' ), mc.consent_field ), value: mc.consent_field } ]
									: []
							),
							help: consentMissing
								? __( 'This field no longer exists, so nobody is subscribed. Choose another field, or "Always subscribe".', 'launch-interactive-forms' )
								: undefined,
							onChange: function ( v ) {
								change( 'consent_field', v );
							},
						} ),
						el( c.ToggleControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Double opt-in (send a confirmation email)', 'launch-interactive-forms' ),
							checked: !! mc.double_optin,
							onChange: function ( v ) {
								change( 'double_optin', v );
							},
						} ),
						el( c.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Tags', 'launch-interactive-forms' ),
							help: __( 'Comma separated', 'launch-interactive-forms' ),
							value: mc.tags,
							onChange: function ( v ) {
								change( 'tags', v );
							},
						} ),
						el( 'h4', null, __( 'Merge fields', 'launch-interactive-forms' ) ),
						mergeFields.map( function ( m, i ) {
							var setMap = function ( key, value ) {
								var next = mergeFields.slice();
								next[ i ] = set( m, key, value );
								change( 'merge_fields', next );
							};
							return el(
								'div',
								{ className: 'lif-builder__row lif-builder__rule', key: i },
								el( c.TextControl, {
									__nextHasNoMarginBottom: true,
									__next40pxDefaultSize: true,
									label: __( 'Mailchimp tag', 'launch-interactive-forms' ),
									placeholder: 'FNAME',
									value: m.tag,
									onChange: function ( v ) {
										setMap( 'tag', v.toUpperCase() );
									},
								} ),
								el( c.SelectControl, {
									__nextHasNoMarginBottom: true,
									__next40pxDefaultSize: true,
									label: __( 'Form field', 'launch-interactive-forms' ),
									value: m.field,
									options: fieldChoices( config.fields, null, '—' ),
									onChange: function ( v ) {
										setMap( 'field', v );
									},
								} ),
								el( c.Button, {
									icon: 'no-alt',
									label: __( 'Remove', 'launch-interactive-forms' ),
									isDestructive: true,
									onClick: function () {
										change(
											'merge_fields',
											mergeFields.filter( function ( x, j ) {
												return j !== i;
											} )
										);
									},
								} )
							);
						} ),
						el(
							c.Button,
							{
								variant: 'secondary',
								onClick: function () {
									change( 'merge_fields', mergeFields.concat( [ { tag: '', field: '' } ] ) );
								},
							},
							__( 'Add merge field', 'launch-interactive-forms' )
						)
					)
			);
		}

		/* ---------------- Zoho CRM */
		function ZohoPanel( props ) {
			var config = props.config;
			var zoho = config.zoho;
			var map = zoho.field_map || [];
			var fieldsState = useState( null );
			var errorState = useState( '' );
			var zohoFields = fieldsState[ 0 ];
			var change = function ( key, value ) {
				props.onChange( Object.assign( {}, config, { zoho: set( zoho, key, value ) } ) );
			};
			var consentMissing =
				!! zoho.consent_field &&
				! ( config.fields || [] ).some( function ( f ) {
					return f.name === zoho.consent_field;
				} );

			useEffect(
				function () {
					if ( ! data.zohoConnected || ! zoho.enabled || fieldsState[ 0 ] !== null || ! wp.apiFetch ) {
						return;
					}
					wp.apiFetch( { path: '/liforms/v1/zoho/fields' } ).then(
						function ( list ) {
							fieldsState[ 1 ]( list );
						},
						function ( err ) {
							fieldsState[ 1 ]( [] );
							errorState[ 1 ]( ( err && err.message ) || 'Error' );
						}
					);
				},
				[ zoho.enabled ]
			);

			if ( ! data.zohoConnected ) {
				return el(
					c.Notice,
					{ status: 'info', isDismissible: false },
					__( 'Connect Zoho CRM in Forms → Settings to create a lead when someone submits this form.', 'launch-interactive-forms' ),
					data.settingsUrl ? el( Fragment, null, ' ', el( 'a', { href: data.settingsUrl + '#lif-zoho', target: '_blank', rel: 'noreferrer' }, __( 'Open settings', 'launch-interactive-forms' ) ) ) : null
				);
			}

			var typeOf = function ( apiName ) {
				var found = ( zohoFields || [] ).filter( function ( z ) {
					return z.api_name === apiName;
				} )[ 0 ];
				return found ? found.type : '';
			};
			var zohoChoices = [ { label: '—', value: '' }, { label: __( 'Full name (split into First and Last Name)', 'launch-interactive-forms' ), value: h.ZOHO_FULL_NAME } ].concat(
				( zohoFields || [] ).map( function ( z ) {
					return { label: z.label + ( z.required ? ' *' : '' ) + ' (' + z.api_name + ')', value: z.api_name };
				} )
			);
			var setRow = function ( i, next ) {
				var rows = map.slice();
				rows[ i ] = next;
				change( 'field_map', rows );
			};
			var missing = zohoFields && zohoFields.length ? h.missingZohoFields( map, zohoFields ) : [];

			return el(
				'div',
				{ className: 'lif-builder__panel' },
				el( c.ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Create a Zoho CRM lead', 'launch-interactive-forms' ),
					checked: !! zoho.enabled,
					onChange: function ( v ) {
						// First time on: start from a mapping guessed from the field names.
						props.onChange(
							Object.assign( {}, config, {
								zoho: Object.assign( {}, zoho, { enabled: v }, v && ! map.length ? { field_map: h.suggestZohoMap( config.fields ) } : {} ),
							} )
						);
					},
				} ),
				zoho.enabled &&
					el(
						Fragment,
						null,
						errorState[ 0 ] && el( c.Notice, { status: 'error', isDismissible: false }, errorState[ 0 ] ),
						missing.length > 0 &&
							el(
								c.Notice,
								{ status: 'warning', isDismissible: false },
								wp.i18n.sprintf(
									/* translators: %s: list of Zoho field names. */
									__( 'Zoho requires: %s. Map a form field to each, or the lead may be rejected (a missing Last Name is filled with the email address).', 'launch-interactive-forms' ),
									missing
										.map( function ( z ) {
											return z.label;
										} )
										.join( ', ' )
								)
							),
						el( 'h4', null, __( 'Field mapping', 'launch-interactive-forms' ) ),
						zohoFields === null && el( 'p', { className: 'lif-builder__hint' }, __( 'Loading Zoho fields…', 'launch-interactive-forms' ) ),
						map.map( function ( m, i ) {
							return el(
								'div',
								{ className: 'lif-builder__row lif-builder__rule', key: i },
								el( c.SelectControl, {
									__nextHasNoMarginBottom: true,
									__next40pxDefaultSize: true,
									label: __( 'Form field', 'launch-interactive-forms' ),
									value: m.field,
									options: fieldChoices( config.fields, null, '—' ),
									onChange: function ( v ) {
										setRow( i, set( m, 'field', v ) );
									},
								} ),
								zohoFields && zohoFields.length
									? el( c.SelectControl, {
											__nextHasNoMarginBottom: true,
											__next40pxDefaultSize: true,
											label: __( 'Zoho field', 'launch-interactive-forms' ),
											value: m.zoho,
											options: zohoChoices.some( function ( o ) {
												return o.value === m.zoho;
											} )
												? zohoChoices
												: zohoChoices.concat( [ { label: m.zoho, value: m.zoho } ] ),
											onChange: function ( v ) {
												setRow( i, Object.assign( {}, m, { zoho: v, type: typeOf( v ) } ) );
											},
									  } )
									: el( c.TextControl, {
											__nextHasNoMarginBottom: true,
											__next40pxDefaultSize: true,
											label: __( 'Zoho field (API name)', 'launch-interactive-forms' ),
											placeholder: 'Last_Name',
											value: m.zoho,
											onChange: function ( v ) {
												setRow( i, set( m, 'zoho', v.replace( /[^A-Za-z0-9_$]/g, '' ) ) );
											},
									  } ),
								el( c.Button, {
									icon: 'no-alt',
									label: __( 'Remove', 'launch-interactive-forms' ),
									isDestructive: true,
									onClick: function () {
										change(
											'field_map',
											map.filter( function ( x, j ) {
												return j !== i;
											} )
										);
									},
								} )
							);
						} ),
						el(
							c.Button,
							{
								variant: 'secondary',
								onClick: function () {
									change( 'field_map', map.concat( [ { zoho: '', type: '', field: '' } ] ) );
								},
							},
							__( 'Add field', 'launch-interactive-forms' )
						),
						el( 'h4', null, __( 'Options', 'launch-interactive-forms' ) ),
						el( c.TextControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Lead Source', 'launch-interactive-forms' ),
							help: __( 'Sets the lead\'s Lead Source, e.g. "Website" (must match one of the choices in Zoho). Leave blank to skip.', 'launch-interactive-forms' ),
							value: zoho.lead_source,
							onChange: function ( v ) {
								change( 'lead_source', v );
							},
						} ),
						el( c.SelectControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Only create a lead when this field is ticked', 'launch-interactive-forms' ),
							value: zoho.consent_field,
							options: fieldChoices(
								config.fields,
								function ( f ) {
									return f.type === 'consent' || f.type === 'checkbox';
								},
								__( 'Always', 'launch-interactive-forms' )
							).concat(
								consentMissing
									? [ { label: wp.i18n.sprintf( /* translators: %s: field name. */ __( 'Removed field (%s)', 'launch-interactive-forms' ), zoho.consent_field ), value: zoho.consent_field } ]
									: []
							),
							help: consentMissing
								? __( 'This field no longer exists, so no leads are created. Choose another field, or "Always".', 'launch-interactive-forms' )
								: undefined,
							onChange: function ( v ) {
								change( 'consent_field', v );
							},
						} ),
						el( c.ToggleControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Update the existing lead when the email address matches', 'launch-interactive-forms' ),
							checked: !! zoho.update_existing,
							onChange: function ( v ) {
								change( 'update_existing', v );
							},
						} ),
						el( c.ToggleControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Run Zoho workflow rules for the new lead', 'launch-interactive-forms' ),
							checked: !! zoho.run_workflows,
							onChange: function ( v ) {
								change( 'run_workflows', v );
							},
						} ),
						el( c.ToggleControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Add all submitted fields to the lead\'s Description', 'launch-interactive-forms' ),
							checked: !! zoho.description_all_fields,
							onChange: function ( v ) {
								change( 'description_all_fields', v );
							},
						} ),
						el( 'p', { className: 'lif-builder__hint' }, __( 'The result of each attempt is shown on the submission under Forms → Submissions.', 'launch-interactive-forms' ) )
					)
			);
		}

		/* ---------------- root */
		function Builder( props ) {
			var config = h.normalizeConfig( props.config, data.defaults );
			var onChange = props.onChange;
			return el(
				'div',
				{ className: 'lif-builder' },
				el(
					c.TabPanel,
					{
						className: 'lif-builder__tabs',
						tabs: [
							{ name: 'fields', title: __( 'Fields', 'launch-interactive-forms' ) },
							{ name: 'settings', title: __( 'Settings', 'launch-interactive-forms' ) },
							{ name: 'notifications', title: __( 'Notifications', 'launch-interactive-forms' ) },
							{ name: 'mailchimp', title: __( 'Mailchimp', 'launch-interactive-forms' ) },
							{ name: 'zoho', title: __( 'Zoho CRM', 'launch-interactive-forms' ) },
						],
					},
					function ( tab ) {
						var panelProps = { config: config, onChange: onChange };
						if ( tab.name === 'settings' ) {
							return el( SettingsPanel, panelProps );
						}
						if ( tab.name === 'notifications' ) {
							return el( NotificationsPanel, panelProps );
						}
						if ( tab.name === 'mailchimp' ) {
							return el( MailchimpPanel, panelProps );
						}
						if ( tab.name === 'zoho' ) {
							return el( ZohoPanel, panelProps );
						}
						return el( FieldsPanel, panelProps );
					}
				)
			);
		}

		return Object.assign( {}, h, { Builder: Builder, data: data } );
	},
} );
