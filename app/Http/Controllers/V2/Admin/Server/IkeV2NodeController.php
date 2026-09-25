<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\IkeV2NodeService;
use Illuminate\Http\Request;

// ManduVPN PP-C4-1B: admin IKEv2 node token lifecycle + safe metadata (logic in IkeV2NodeService).
// The plaintext token appears ONLY in the issue/rotate response; hashes are never returned.
class IkeV2NodeController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        return $this->success(IkeV2NodeService::getMetadata((int) $request->input('id')));
    }

    public function issue(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $id = (int) $request->input('id');
        $token = IkeV2NodeService::issue($id);
        return $this->success(['token' => $token, 'node' => IkeV2NodeService::getMetadata($id)]);
    }

    public function rotate(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $id = (int) $request->input('id');
        $token = IkeV2NodeService::rotate($id);
        return $this->success(['token' => $token, 'node' => IkeV2NodeService::getMetadata($id)]);
    }

    public function revoke(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        return $this->success(['revoked' => IkeV2NodeService::revoke((int) $request->input('id'))]);
    }
}
