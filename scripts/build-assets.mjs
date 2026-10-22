/* Build-time only. WordPress needs neither Node nor npm to run the plugin. */
import {build} from 'esbuild';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
for (const name of ['app.js', 'admin.js', 'install.js', 'app.css', 'admin.css']) {
    await build({
        absWorkingDir:root,
        entryPoints:['assets/' + name],
        outfile:'assets/' + name.replace(/\.(js|css)$/, '.min.$1'),
        bundle:false,
        minify:true,
        target:name.endsWith('.js') ? ['es2020'] : ['chrome90', 'firefox90', 'safari14'],
        legalComments:'inline',
        charset:'utf8',
        banner:name.endsWith('.js') ? {js:'/*! WP My Family Tree | GPL-3.0-or-later | Source: ' + name + ' */'} : {css:'/*! WP My Family Tree | GPL-3.0-or-later | Source: ' + name + ' */'},
        logLevel:'warning'
    });
    console.log('Built ' + name.replace(/\.(js|css)$/, '.min.$1'));
}
