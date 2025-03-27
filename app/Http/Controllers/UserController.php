<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * 显示用户列表
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
     */
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->get();
        return view('users.index', compact('users'));
    }

    /**
     * 显示创建用户表单
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
     */
    public function create()
    {
        return view('users.create');
    }

    /**
     * 存储新创建的用户
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        try {
            // 获取用户输入数据
            $name = $request->input('name');
            $username = $request->input('username');
            $email = $request->input('email');
            $password = $request->input('password');
            $password_confirmation = $request->input('password_confirmation');
            $is_admin = $request->has('is_admin') ? true : false;

            // 手动验证
            $errors = [];
            
            if (empty($name)) {
                $errors['name'] = '姓名不能为空';
            }
            
            if (empty($username)) {
                $errors['username'] = '用户名不能为空';
            } elseif (User::where('username', $username)->exists()) {
                $errors['username'] = '用户名已被使用';
            }
            
            if (!empty($email) && User::where('email', $email)->exists()) {
                $errors['email'] = '邮箱已被使用';
            }
            
            if (empty($password)) {
                $errors['password'] = '密码不能为空';
            } elseif (strlen($password) < 8) {
                $errors['password'] = '密码长度不能少于8个字符';
            } elseif ($password !== $password_confirmation) {
                $errors['password'] = '两次输入的密码不一致';
            }
            
            // 如果有验证错误，返回
            if (!empty($errors)) {
                return redirect()->back()
                    ->withErrors($errors)
                    ->withInput($request->except('password', 'password_confirmation'));
            }

            // 无错误，直接创建用户
            $user = new User();
            $user->name = $name;
            $user->username = $username;
            $user->email = $email;
            $user->password = Hash::make($password);
            $user->is_admin = $is_admin;
            $user->save();

            // 记录用户创建日志
            Log::info('用户创建成功', [
                'user_id' => $user->id,
                'username' => $user->username,
                'created_by' => auth()->id()
            ]);

            return redirect()->route('users.index')
                ->with('success', '用户已成功创建。');
            
        } catch (\Exception $e) {
            // 记录异常
            Log::error('用户创建失败', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('error', '创建用户时发生错误：' . $e->getMessage());
        }
    }

    /**
     * 显示指定用户
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
     */
    public function show(User $user)
    {
        return view('users.show', compact('user'));
    }

    /**
     * 显示编辑用户表单
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
     */
    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    /**
     * 更新指定用户
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'password' => 'nullable|string|min:8|confirmed',
            'is_admin' => 'boolean',
        ]);

        $data = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'is_admin' => $request->has('is_admin') ? 1 : 0,
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        // 保存修改前的用户信息
        $oldData = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_admin' => $user->is_admin
        ];

        $user->update($data);

        // 记录用户更新日志
        Log::info('用户信息已更新', [
            'user_id' => $user->id,
            'updated_by' => auth()->id(),
            'old_data' => $oldData,
            'new_data' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_admin' => $user->is_admin
            ],
            'password_changed' => $request->filled('password')
        ]);

        return redirect()->route('users.index')
            ->with('success', '用户已成功更新。');
    }

    /**
     * 删除指定用户
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(User $user)
    {
        // 防止删除自己
        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')
                ->with('error', '您不能删除自己的账户。');
        }

        // 记录用户删除前的信息
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_admin' => $user->is_admin
        ];

        $user->delete();

        // 记录用户删除日志
        Log::info('用户已删除', [
            'deleted_user' => $userData,
            'deleted_by' => auth()->id(),
        ]);

        return redirect()->route('users.index')
            ->with('success', '用户已成功删除。');
    }
}
