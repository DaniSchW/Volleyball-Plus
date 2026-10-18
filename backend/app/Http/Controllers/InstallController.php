<?php

namespace App\Http\Controllers;

use App\Support\Install\Installation;
use App\Support\Install\Installer;
use App\Support\Install\InstallException;
use App\Support\Install\Requirements;
use App\Support\Install\SetupCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Browser-based installation for hosting without SSH. Runs without session
 * or cookies (there is no application key yet); the one-time setup code
 * from a server file protects it instead.
 */
class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        abort_if(Installation::isInstalled(), 404);

        return $this->form($request);
    }

    public function store(Request $request, Installer $installer): Response
    {
        abort_if(Installation::isInstalled(), 404);

        $checks = Requirements::check($request);
        if (! Requirements::passes($checks)) {
            return $this->form($request, errors: [__('install.errors.requirements')]);
        }

        if (! SetupCode::matches($request->input('setup_code'))) {
            return $this->form($request, errors: [__('install.errors.setup_code')], status: 422);
        }

        $validator = Validator::make($request->all(), $this->rules(), [], $this->attributeNames());
        if ($validator->fails()) {
            return $this->form($request, errors: $validator->errors()->all(), status: 422);
        }

        try {
            $result = $installer->install($validator->validated());
        } catch (InstallException $e) {
            return $this->form($request, errors: [$e->getMessage()], status: 422);
        }

        return response()->view('install.done', $result);
    }

    /**
     * @param  list<string>  $errors
     */
    private function form(Request $request, array $errors = [], int $status = 200): Response
    {
        $checks = Requirements::check($request);

        return response()->view('install.form', [
            'checks' => $checks,
            'passes' => Requirements::passes($checks),
            'codeReady' => SetupCode::ensure(),
            'codePath' => SetupCode::displayPath(),
            'errors' => $errors,
            // Passwords are never echoed back into the form.
            'old' => $request->except(['setup_code', 'db_password', 'smtp_password']),
            'defaults' => [
                'app_url' => 'https://'.$request->getHttpHost(),
                'db_host' => 'localhost',
                'db_port' => '3306',
                'mail_mailer' => 'phpmail',
                'smtp_port' => '587',
                'smtp_scheme' => 'smtp',
                'mail_from_address' => 'noreply@'.$request->getHost(),
            ],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'app_url' => ['required', 'url:https', 'max:255'],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'mail_mailer' => ['required', 'in:phpmail,smtp'],
            'mail_from_address' => ['required', 'email', 'max:255'],
            'smtp_host' => ['required_if:mail_mailer,smtp', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['required_if:mail_mailer,smtp', 'nullable', 'integer', 'between:1,65535'],
            'smtp_scheme' => ['required_if:mail_mailer,smtp', 'nullable', 'in:smtp,smtps'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'tenant_name' => ['required', 'string', 'max:120'],
            'tenant_slug' => ['required', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'min:2', 'max:64'],
            'admin_first_name' => ['required', 'string', 'max:80'],
            'admin_last_name' => ['required', 'string', 'max:80'],
            'admin_gender' => ['required', 'in:male,female'],
            'admin_email' => ['required', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        return collect(array_keys($this->rules()))
            ->mapWithKeys(fn (string $field) => [$field => __("install.fields.{$field}")])
            ->all();
    }
}
