module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	env: {
		browser: true,
	},
	rules: {
		// Vanilla JS — no JSX/React in this plugin.
		'react/react-in-jsx-scope': 'off',
		'react/prop-types': 'off',
		// WordPress JS uses spaces inside parentheses (matching PHP coding standards).
		// Prettier has no option for this convention; disable it for vanilla JS.
		'prettier/prettier': 'off',
	},
};
