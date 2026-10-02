<?php

namespace App\Http\Requests\Server\Addons;

use Illuminate\Foundation\Http\FormRequest;

class InsightsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('central_authenticated') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            // The toolkit refuses anything else too; checked here so a bad
            // value is a 422 that names the field, not the addon's refusal.
            'field' => ['sometimes', 'string', 'in:application_id,request,ip,method,url,protocol,user_agent,status_code,referer,country,is_robots_txt,is_sitemap_url,is_xmlrpc_request,is_bot_request,bot_name,mime_type,device_type,device_os'],
            'status_code' => ['sometimes', 'string', 'in:1xx,2xx,3xx,4xx,5xx'],
        ];
    }
}
