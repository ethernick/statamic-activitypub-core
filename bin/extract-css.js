const fs = require('fs');

const version = process.argv[2] || 'v5';
const jsPath = `dist/${version}/js/cp.js`;
const cssDir = `dist/${version}/css`;
const cssPath = `${cssDir}/cp.css`;

if (!fs.existsSync(jsPath)) {
    console.error(`JS file not found: ${jsPath}`);
    process.exit(1);
}

const js = fs.readFileSync(jsPath, 'utf8');
const match = js.match(/[a-zA-Z0-9_$]+\.textContent=`([\s\S]*?)`;/);

if (match) {
    fs.mkdirSync(cssDir, { recursive: true });
    // In the inlined JS template string, backslashes are escaped as \\
    const css = match[1].replace(/\\\\/g, '\\');
    fs.writeFileSync(cssPath, css);
    console.log(`Extracted CSS to ${cssPath} (${fs.statSync(cssPath).size} bytes)`);
} else {
    console.warn(`No inlined CSS found in ${jsPath}`);
}
