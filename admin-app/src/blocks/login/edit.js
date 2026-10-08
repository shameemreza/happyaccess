/* eslint-disable import/no-unresolved -- WordPress loads this script, so the build keeps it external and it is not installed. */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { redirectTo, toggleStyle } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Login form', 'happyaccess' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Redirect after login', 'happyaccess' ) }
						help={ __(
							'A page on this site. Leave empty to stay on the same page.',
							'happyaccess'
						) }
						type="url"
						value={ redirectTo }
						onChange={ ( value ) =>
							setAttributes( { redirectTo: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Toggle style', 'happyaccess' ) }
						value={ toggleStyle }
						options={ [
							{
								value: '',
								label: __( 'Site default', 'happyaccess' ),
							},
							{
								value: 'link',
								label: __( 'Text link', 'happyaccess' ),
							},
							{
								value: 'button',
								label: __( 'Full button', 'happyaccess' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { toggleStyle: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<Disabled>
					<ServerSideRender
						block="happyaccess/login"
						attributes={ attributes }
						EmptyResponsePlaceholder={ () => (
							<p>
								{ __(
									'The login form shows here while the passwordless login feature is on.',
									'happyaccess'
								) }
							</p>
						) }
					/>
				</Disabled>
			</div>
		</>
	);
}
