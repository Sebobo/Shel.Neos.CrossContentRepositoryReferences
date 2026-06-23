const esbuild = require('esbuild');
const CssModulesPlugin = require('esbuild-css-modules-plugin');
const extensibilityMap = require('@neos-project/neos-ui-extensibility/extensibilityMap.json');
const isWatch = process.argv.includes('--watch');

/** @type {import("esbuild").BuildOptions} */
const options = {
    logLevel: 'info',
    bundle: true,
    minify: !isWatch,
    target: 'es2020',
    entryPoints: { Plugin: 'src/index.js' },
    plugins: [
        CssModulesPlugin({
            // @see https://github.com/indooorsman/esbuild-css-modules-plugin/blob/main/index.d.ts for more details
            force: true,
            localsConvention: 'camelCaseOnly',
            namedExports: true,
            inject: false,
            // The default pattern `__[local]_[hash]__` doesn't end with `[local]`,
            // which lightningcss requires when CSS grid line names are used
            // (grid-template-areas). The pattern MUST end with `[local]`.
            pattern: '[name]-[local]',
        }),
    ],
    sourcemap: true,
    loader: {
        '.js': 'tsx',
    },
    alias: extensibilityMap,
    outdir: '../../Resources/Public/Assets',
};

if (isWatch) {
    esbuild.context(options).then((ctx) => ctx.watch());
} else {
    esbuild.build(options);
}
