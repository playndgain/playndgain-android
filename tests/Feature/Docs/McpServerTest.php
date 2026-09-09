<?php

use Illuminate\Testing\TestResponse;

/**
 * /mcp is a read-only Model Context Protocol server over the docs. It speaks
 * JSON-RPC over Streamable HTTP, has three tools, and can reach nothing but
 * published pages.
 */
beforeEach(function () {
    config()->set('app.url', 'https://docs.example.test');
});

function rpc(string $method, array $params = [], mixed $id = 1): TestResponse
{
    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
}

it('answers initialize with the protocol the client asked for and a tools capability', function () {
    rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '0']])
        ->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('result.protocolVersion', '2025-03-26')
        ->assertJsonPath('result.capabilities.tools.listChanged', false)
        ->assertJsonPath('result.serverInfo.name', 'PLAYNDGAIN docs');

    rpc('initialize', ['protocolVersion' => '1999-01-01'])
        ->assertJsonPath('result.protocolVersion', '2025-06-18');
});

it('accepts the initialized notification with an empty 202 and answers ping', function () {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
        ->assertStatus(202);

    rpc('ping')->assertOk()->assertJsonPath('result', []);
});

it('lists exactly three tools with JSON schemas', function () {
    $tools = rpc('tools/list')->assertOk()->json('result.tools');

    expect(array_column($tools, 'name'))->toBe(['list_pages', 'read_page', 'search_docs']);

    foreach ($tools as $tool) {
        expect($tool)->toHaveKeys(['name', 'description', 'inputSchema']);
        expect($tool['inputSchema']['type'])->toBe('object');
    }
});

it('lists every published page with its url', function () {
    $text = rpc('tools/call', ['name' => 'list_pages', 'arguments' => []])
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->json('result.content.0.text');

    foreach (array_merge(...array_column(config('docs.nav'), 'pages')) as $page) {
        expect($text)->toContain("- {$page['slug']}: {$page['title']} (https://docs.example.test/docs/{$page['slug']})");
    }
});

it('reads a page as Markdown and refuses an unpublished slug without touching the filesystem path', function () {
    $text = rpc('tools/call', ['name' => 'read_page', 'arguments' => ['slug' => 'chargebacks-reversals']])
        ->assertOk()
        ->json('result.content.0.text');

    expect($text)
        ->toStartWith('# Reversals')
        ->toContain('you keep paying players for revenue you were never paid')
        ->not->toContain('{APP_URL}');

    foreach (['nav', '../../.env', 'prompts/integration', 'does-not-exist'] as $slug) {
        expect(rpc('tools/call', ['name' => 'read_page', 'arguments' => ['slug' => $slug]])->json('result.content.0.text'))
            ->toContain('No page with slug');
    }
});

it('searches and ranks a title match first', function () {
    $text = rpc('tools/call', ['name' => 'search_docs', 'arguments' => ['query' => 'signature']])
        ->assertOk()
        ->json('result.content.0.text');

    expect($text)
        ->toStartWith('## Verifying the signature (signature-verification)')
        ->toContain('https://docs.example.test/docs/signature-verification');

    expect(rpc('tools/call', ['name' => 'search_docs', 'arguments' => ['query' => 'zzzzqqqq']])->json('result.content.0.text'))
        ->toContain('Nothing in the documentation matches');
});

it('returns JSON-RPC errors for an unknown tool, an unknown method and a malformed body', function () {
    rpc('tools/call', ['name' => 'delete_everything'])->assertOk()->assertJsonPath('error.code', -32602);
    rpc('resources/list')->assertOk()->assertJsonPath('error.code', -32601);

    $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{not json')
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32700);

    $this->postJson('/mcp', ['id' => 1, 'method' => 'ping'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32600);
});

it('answers 405 to anything but POST', function () {
    $this->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    $this->delete('/mcp')->assertStatus(405);
});
