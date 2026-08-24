<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // People type mobile numbers with spaces, dashes and brackets. Strip
        // those before validating rather than rejecting a number that is
        // perfectly valid to a human.
        if (is_string($request->input('phone'))) {
            $request->merge([
                'phone' => preg_replace('/[^0-9+]/', '', $request->input('phone')),
            ]);
        }

        $validated = $request->validate([
            'first_name'  => 'required|string|max:255',
            'last_name'   => 'required|string|max:255',
            // Required, not nullable: order updates go out by SMS, and
            // OrderController::store skips sending when phone is null — so a
            // customer without one silently never hears about their order.
            'phone'       => ['required', 'string', 'max:20', 'regex:/^(\+?63|0)9\d{9}$/'],
            'email'       => 'required|email|max:255|unique:users,email',
            'password'    => 'required|string|min:8|confirmed',
            'address'     => 'nullable|string',
        ], [
            'phone.regex' => 'Enter a valid PH mobile number, e.g. 09171234567.',
        ]);

        $user = User::create([
            'role'       => 'customer',
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'phone'      => $validated['phone'] ?? null,
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'address'    => $validated['address'] ?? null,
        ]);

        $token = $user->createToken('mobile_app')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('mobile_app')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}