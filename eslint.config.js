/**
 * ESLint flat config (ESLint 10, @wordpress/eslint-plugin 28).
 *
 * Flat config does not climb parent directories, so running the linter from
 * a worktree under .claude/worktrees/ no longer picks up the main checkout's
 * config as well.
 */

/* eslint-disable import/no-extraneous-dependencies -- transitive deps of @wordpress/scripts */
const wpPlugin = require( '@wordpress/eslint-plugin' );
const globals = require( 'globals' );
/* eslint-enable import/no-extraneous-dependencies */

module.exports = [
	{
		ignores: [
			'.claude/**',
			'build/**',
			'lib/**',
			'plans/**',
			'vendor/**',
			'node_modules/**',
		],
	},
	...wpPlugin.configs.recommended,
	{
		languageOptions: {
			globals: {
				...globals.browser,
				vcSettings: 'readonly',
			},
		},
		rules: {
			// Vanilla JS — no JSX/React in this plugin.
			'react/react-in-jsx-scope': 'off',
			'react/prop-types': 'off',
			// WordPress JS uses spaces inside parentheses (matching PHP coding standards).
			// Prettier has no option for this convention; disable it for vanilla JS.
			'prettier/prettier': 'off',
		},
	},
];
