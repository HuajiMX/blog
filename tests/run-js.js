#!/usr/bin/env node
/**
 * Browser-side math pipeline tests.
 *
 * Runs katex-core.js (the code the editor preview uses) against the same cases
 * as tests/run-php.php, to prove PHP and JavaScript produce identical markup.
 * No browser needed: the module only touches `window`, which we shim.
 *
 * Usage: node tests/run-js.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.dirname(__dirname);
const suite = JSON.parse(fs.readFileSync(path.join(__dirname, 'math-cases.json'), 'utf8'));
const corePath = path.join(root, 'wp-content/plugins/katex/assets/katex-core.js');
const grammarPath = path.join(root, 'wp-content/plugins/katex/scripts/grammar.php');
const markedPath = path.join(
  root,
  'wp-content/plugins/wp-githuber-md-1.17.0/assets/vendor/editor.md/lib/marked.20190506.min.js'
);

/**
 * The syntax table lives in PHP. Read the rule patterns out of it with a
 * tolerant scan so this test needs no PHP at run time.
 */
function readRulesFromPhp(source) {
  const rules = [];
  const block = source.match(/katex_math_rules\(\)\s*\{[\s\S]*?apply_filters\(\s*'katex_math_rules',\s*array\(([\s\S]*?)\n\s*\)\s*\);/);
  if (!block) {
    throw new Error('could not locate katex_math_rules() in grammar.php');
  }

  const ruleRe = /'id'\s*=>\s*'([^']+)'[\s\S]*?'display'\s*=>\s*(true|false)[\s\S]*?'block'\s*=>\s*(true|false)[\s\S]*?'pattern'\s*=>\s*'((?:[^'\\]|\\.)*)'/g;
  let match;
  while ((match = ruleRe.exec(block[1])) !== null) {
    const parsed = phpPattern(match[4]);
    rules.push({
      id: match[1],
      display: match[2] === 'true',
      block: match[3] === 'true',
      pattern: parsed.body,
      flags: parsed.flags,
    });
  }

  if (!rules.length) {
    throw new Error('no rules parsed from grammar.php');
  }
  return rules;
}

/**
 * The literal-region patterns live in katex_markdown_literal_patterns().
 *
 * The shortcode pattern is generated from the registered tags at run time, so
 * it cannot be lifted out of the source; the tests use the two KaTeX tags,
 * which are the ones that matter for math.
 */
function readLiteralPatternsFromPhp(source) {
  const block = source.match(/function katex_markdown_literal_patterns\(\)\s*\{[\s\S]*?array\(([\s\S]*?)\n\s*\);/);
  if (!block) {
    throw new Error('could not locate katex_markdown_literal_patterns() in grammar.php');
  }

  const patterns = [];
  const re = /'((?:[^'\\]|\\.)*)'/g;
  let match;
  while ((match = re.exec(block[1])) !== null) {
    const raw = match[1];
    if (raw.indexOf('/') !== 0) {
      continue;
    }
    const parsed = phpPattern(raw);
    patterns.push({ pattern: parsed.body, flags: parsed.flags });
  }

  // Mirror the generated shortcode pattern for the two KaTeX tags.
  patterns.push({ pattern: '\\[/?(?:katex|latex)\\b[^\\]\\n]*\\]', flags: 'i' });

  if (!patterns.length) {
    throw new Error('no literal patterns parsed from grammar.php');
  }
  return patterns;
}

/**
 * Split a PHP regex literal into body and JavaScript-compatible flags.
 */
function phpPattern(raw) {
  // PHP single-quoted escape: only \\ and \' are special.
  const unescaped = raw.replace(/\\([\\'])/g, '$1');
  const delimiter = unescaped[0];
  const end = unescaped.lastIndexOf(delimiter);
  const body = unescaped.slice(1, end);
  const modifiers = unescaped.slice(end + 1);
  let flags = '';
  if (modifiers.indexOf('i') !== -1) flags += 'i';
  if (modifiers.indexOf('m') !== -1) flags += 'm';
  if (modifiers.indexOf('s') !== -1) flags += 's';
  if (modifiers.indexOf('u') !== -1) flags += 'u';
  return { body, flags };
}

// Editor.md ships marked; the preview really runs against it, so the test does
// too. Loading it keeps "does the regex survive a real Markdown pass" honest.
function loadMarked() {
  const sandbox = { window: {}, self: {}, console, module: undefined, exports: undefined };
  vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(markedPath, 'utf8'), sandbox);
  if (!sandbox.marked || typeof sandbox.marked !== 'function') {
    throw new Error('could not load marked from the Githuber vendor directory');
  }
  return sandbox.marked;
}

const marked = loadMarked();

function render(markdown) {
  marked.setOptions({ gfm: true, tables: true, breaks: true, sanitize: false });
  return marked(markdown);
}

function collect(html) {
  const elements = [];
  const re = /<(span|div)\b[^>]*class="[^"]*\bkatex-eq\b[^"]*"[^>]*>/gi;
  let match;
  while ((match = re.exec(html)) !== null) {
    const tag = match[0];
    const display = /data-katex-display="true"/i.test(tag);
    const texMatch = tag.match(/data-katex-tex="([^"]*)"/i);
    elements.push({
      tag: match[1].toLowerCase(),
      display,
      tex: texMatch ? texMatch[1].replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&') : null,
    });
  }
  return {
    elements: elements.length,
    tags: elements.map((e) => e.tag),
    display: elements.map((e) => e.display),
    tex: elements.map((e) => (e.tex === null ? null : e.tex.replace(/&#10;/g, '\n'))),
    html,
  };
}

const grammarSource = fs.readFileSync(grammarPath, 'utf8');
const rules = readRulesFromPhp(grammarSource);
const literal = readLiteralPatternsFromPhp(grammarSource);
const sandbox = {
  window: {
    katexMathConfig: {
      rules,
      literal,
      katex: { output: 'html' },
      render: {},
    },
  },
  document: undefined,
};
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(corePath, 'utf8'), sandbox);

const core = sandbox.window.iroKatex;
let failures = 0;

suite.cases.forEach((testCase) => {
  if (testCase.phpOnly) {
    console.log(`skip  ${testCase.name}  (${testCase.phpOnly})`);
    return;
  }

  const expect = testCase.expect || {};
  const protectedMd = core.protectMarkdown(testCase.markdown);
  const html = core.restoreMarkdown(render(protectedMd.text), protectedMd.tokens);
  const probe = collect(html);
  const problems = [];

  if (expect.elements !== undefined && probe.elements !== expect.elements) {
    problems.push(`expected ${expect.elements} element(s), found ${probe.elements}`);
  }
  if (expect.tags && JSON.stringify(probe.tags) !== JSON.stringify(expect.tags)) {
    problems.push(`tags: expected ${JSON.stringify(expect.tags)}, found ${JSON.stringify(probe.tags)}`);
  }
  if (expect.display && JSON.stringify(probe.display) !== JSON.stringify(expect.display)) {
    problems.push(`display: expected ${JSON.stringify(expect.display)}, found ${JSON.stringify(probe.display)}`);
  }
  if (expect.tex && JSON.stringify(probe.tex) !== JSON.stringify(expect.tex)) {
    problems.push(`tex: expected ${JSON.stringify(expect.tex)}, found ${JSON.stringify(probe.tex)}`);
  }
  (expect.textContains || []).forEach((needle) => {
    if (html.indexOf(needle) === -1) {
      problems.push(`missing ${JSON.stringify(needle)}`);
    }
  });
  (expect.absent || []).forEach((needle) => {
    if (html.indexOf(needle) !== -1) {
      problems.push(`unexpected ${JSON.stringify(needle)}`);
    }
  });

  if (problems.length) {
    failures++;
    console.log(`FAIL  ${testCase.name}`);
    problems.forEach((p) => console.log(`        - ${p}`));
    console.log(`        html: ${html.replace(/\n/g, '\\n')}`);
  } else {
    console.log(`ok    ${testCase.name}`);
  }
});

console.log(`\n${suite.cases.length} case(s), ${failures} failure(s)`);
process.exit(failures ? 1 : 0);
