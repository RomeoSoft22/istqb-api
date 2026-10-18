<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email'=>['required','email'], 'password'=>['required','string']]);
        if (! $token = auth('api')->attempt($credentials + ['status'=>'active'])) {
            return response()->json(['message'=>'Credenciales inválidas.'], 401);
        }
        $request->user('api')->forceFill(['last_login_at'=>now()])->save();
        return $this->token($token);
    }

    public function me(): JsonResponse
    {
        $user=auth('api')->user();
        $roles=\Illuminate\Support\Facades\DB::table('user_roles as ur')->join('roles as r','r.id','=','ur.role_id')->where('ur.user_id',$user->id)->pluck('r.name');
        $permissions=\Illuminate\Support\Facades\DB::table('user_roles as ur')->join('role_permissions as rp','rp.role_id','=','ur.role_id')->join('permissions as p','p.id','=','rp.permission_id')->where('ur.user_id',$user->id)->distinct()->pluck('p.name');
        return response()->json(['user'=>$user,'roles'=>$roles,'permissions'=>$permissions]);
    }
    public function refresh(): JsonResponse { return $this->token(auth('api')->refresh()); }
    public function logout(): JsonResponse { auth('api')->logout(); return response()->json(['message'=>'Sesión cerrada.']); }

    private function token(string $token): JsonResponse
    {
        return response()->json(['access_token'=>$token,'token_type'=>'bearer','expires_in'=>auth('api')->factory()->getTTL() * 60]);
    }
}
