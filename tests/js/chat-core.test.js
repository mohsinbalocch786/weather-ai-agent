// Run with: node --test tests/js/*.test.js   (or: composer test:js). No packages required.
//
// public/js/chat-core.js is a classic browser script, so it is evaluated here exactly
// as a browser would: in a fresh global scope, where it registers `ChatCore`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const sandbox = vm.createContext({});
vm.runInContext(readFileSync(new URL('../../public/js/chat-core.js', import.meta.url), 'utf8'), sandbox);

const { escapeHtml, render, createEventParser } = sandbox.ChatCore;

// ---------------------------------------------------------------- escapeHtml

test('escapeHtml escapes every HTML-significant character', () => {
    assert.equal(escapeHtml(`<a href="x" title='y'>&</a>`),
        '&lt;a href=&quot;x&quot; title=&#39;y&#39;&gt;&amp;&lt;/a&gt;');
});

test('escapeHtml leaves ordinary text, unicode and newlines alone', () => {
    const text = 'It is 12°C in Zürich ☀️\nline two';
    assert.equal(escapeHtml(text), text);
});

test('escapeHtml coerces non-strings instead of throwing', () => {
    assert.equal(escapeHtml(42), '42');
    assert.equal(escapeHtml(null), 'null');
});

// -------------------------------------------------------------------- render

test('render turns **bold** into <strong>', () => {
    assert.equal(render('It is **12°C** now'), 'It is <strong>12°C</strong> now');
    assert.equal(render('**a** and **b**'), '<strong>a</strong> and <strong>b</strong>');
});

test('render leaves unmatched or empty bold markers as literal text', () => {
    assert.equal(render('**unfinished'), '**unfinished');
    assert.equal(render('2 ** 3'), '2 ** 3');
    assert.equal(render('****'), '****');
});

test('render does not let bold span lines', () => {
    // "." does not match a newline, so a stray ** cannot swallow the next paragraph.
    assert.equal(render('**a\nb**'), '**a\nb**');
});

test('render neutralises script and event-handler injection', () => {
    const payloads = [
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '<svg onload=alert(1)>',
        '"><script>alert(1)</script>',
        "' onmouseover='alert(1)",
        '<a href="javascript:alert(1)">click</a>',
        '<iframe src="https://evil.example"></iframe>',
    ];

    for (const payload of payloads) {
        const html = render(payload);
        assert.ok(!/[<>]/.test(html), `raw angle bracket survived in: ${html}`);
        assert.ok(!html.includes('"') && !html.includes("'"), `raw quote survived in: ${html}`);
    }
});

test('render escapes HTML that is placed inside bold markers', () => {
    assert.equal(render('**<img src=x onerror=alert(1)>**'),
        '<strong>&lt;img src=x onerror=alert(1)&gt;</strong>');
});

test('render never produces any tag except <strong>, whatever the input (fuzz)', () => {
    // Deterministic PRNG so a failure is reproducible.
    let seed = 20261124;
    const random = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
    const alphabet = ['<', '>', '&', '"', "'", '*', '**', '/', 'a', 'b', ' ', '\n', 'script', 'img', '=', 'on', '&lt;', '\\'];

    for (let i = 0; i < 2000; i++) {
        const input = Array.from({ length: 1 + Math.floor(random() * 25) }, () => alphabet[Math.floor(random() * alphabet.length)]).join('');
        const withoutAllowedTags = render(input).replaceAll('<strong>', '').replaceAll('</strong>', '');

        assert.ok(!/[<>"']/.test(withoutAllowedTags), `unsafe output for ${JSON.stringify(input)}: ${render(input)}`);
    }
});

// -------------------------------------------------------------- createEventParser

const sse = (...events) => events.map((e) => `data: ${JSON.stringify(e)}\n\n`).join('');
const collect = () => {
    const events = [];
    // Objects parsed inside the vm context have a foreign Object prototype; clone them into this realm.
    return { events, parser: createEventParser((event) => events.push(structuredClone(event))) };
};

test('parser emits one event per complete block, in order', () => {
    const { events, parser } = collect();

    parser.push(sse({ type: 'text', delta: 'a' }, { type: 'text', delta: 'b' }, { type: 'done' }));

    assert.deepEqual(events, [{ type: 'text', delta: 'a' }, { type: 'text', delta: 'b' }, { type: 'done' }]);
});

test('parser gives the same result however the stream is chunked (every split point)', () => {
    const stream = sse(
        { type: 'tool', name: 'GetCurrentWeather', label: 'Checking current weather in Zürich' },
        { type: 'text', delta: 'It is **12°C** ☀️ "quoted" {braces}' },
        { type: 'done' },
    );
    const expected = [
        { type: 'tool', name: 'GetCurrentWeather', label: 'Checking current weather in Zürich' },
        { type: 'text', delta: 'It is **12°C** ☀️ "quoted" {braces}' },
        { type: 'done' },
    ];

    for (let cut = 0; cut <= stream.length; cut++) {
        const { events, parser } = collect();
        parser.push(stream.slice(0, cut));
        parser.push(stream.slice(cut));
        assert.deepEqual(events, expected, `split at ${cut}`);
    }

    // And the worst case: one character at a time.
    const { events, parser } = collect();
    for (const char of stream) parser.push(char);
    assert.deepEqual(events, expected);
});

test('parser holds back an incomplete trailing block', () => {
    const { events, parser } = collect();

    parser.push('data: {"type":"text","delta":"x"}\n\ndata: {"type":"text","del');

    assert.deepEqual(events, [{ type: 'text', delta: 'x' }]);

    parser.push('ta":"y"}\n\n');
    assert.deepEqual(events, [{ type: 'text', delta: 'x' }, { type: 'text', delta: 'y' }]);
});

test('parser accepts CRLF line endings, even when split between chunks', () => {
    const { events, parser } = collect();

    parser.push('data: {"type":"text","delta":"a"}\r\n\r');
    assert.deepEqual(events, []);
    parser.push('\ndata: {"type":"done"}\r\n\r\n');

    assert.deepEqual(events, [{ type: 'text', delta: 'a' }, { type: 'done' }]);
});

test('parser ignores comments, other fields and blank blocks', () => {
    const { events, parser } = collect();

    parser.push(': keep-alive\n\nevent: ping\nid: 7\n\n\n\n');
    parser.push('retry: 3000\ndata: {"type":"done"}\n\n');

    assert.deepEqual(events, [{ type: 'done' }]);
});

test('parser skips malformed JSON without losing later events', () => {
    const { events, parser } = collect();

    parser.push('data: {not json}\n\n');
    parser.push('data: \n\n');
    parser.push(sse({ type: 'text', delta: 'still works' }));

    assert.deepEqual(events, [{ type: 'text', delta: 'still works' }]);
});

test('parser keeps going after the handler throws on one event', () => {
    const seen = [];
    const parser = createEventParser((event) => {
        if (event.type === 'bad') throw new Error('handler bug');
        seen.push(event.type);
    });

    parser.push(sse({ type: 'a' }, { type: 'bad' }, { type: 'b' }));

    assert.deepEqual(seen, ['a', 'b']);
});

test('parser handles the exact events the Laravel server sends', () => {
    // Shape produced by App\Services\Chat\WeatherChat (see ChatStreamTest).
    const wire = 'data: {"type":"tool","name":"CompareCitiesWeather","label":"Comparing London and Paris"}\n\n'
        + 'data: {"type":"tool_done","name":"CompareCitiesWeather","successful":true}\n\n'
        + 'data: {"type":"text","delta":"Paris is cooler."}\n\n'
        + 'data: {"type":"conversation","id":"01a20db3-63bf-72d0-8f0e-e8cd9299e7bc","title":"Compare","url":"http:\\/\\/localhost\\/c\\/01a20db3-63bf-72d0-8f0e-e8cd9299e7bc"}\n\n'
        + 'data: {"type":"done"}\n\n';
    const { events, parser } = collect();

    parser.push(wire);

    assert.deepEqual(events.map((e) => e.type), ['tool', 'tool_done', 'text', 'conversation', 'done']);
    assert.equal(events[3].url, 'http://localhost/c/01a20db3-63bf-72d0-8f0e-e8cd9299e7bc');
});
