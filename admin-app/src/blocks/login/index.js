/* eslint-disable import/no-unresolved -- WordPress loads this script, so the build keeps it external and it is not installed. */
import { registerBlockType } from '@wordpress/blocks';
import metadata from '../../../../blocks/login/block.json';
import Edit from './edit';

// The rest comes from the server, which translates the title, description and keywords.
registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
