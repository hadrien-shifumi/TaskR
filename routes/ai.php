<?php

use App\Http\Middleware\AuthenticateMcpAgent;
use App\Mcp\Servers\TaskrServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Server Routes
|--------------------------------------------------------------------------
|
| Statelessness is the whole point here: the endpoint authenticates each
| request with a Sanctum personal access token (no session, no cookies —
| do not wrap these routes in the web middleware group).
|
*/

Mcp::web('/mcp', TaskrServer::class)->middleware(AuthenticateMcpAgent::class);
