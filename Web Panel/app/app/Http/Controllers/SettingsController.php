<?php

namespace App\Http\Controllers;

use App\Models\Users;
use App\Models\Admins;
use App\Models\Api;
use Illuminate\Http\Request;
use Auth;
use App\Models\Settings;
use App\Models\Traffic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Illuminate\Support\Process\ProcessResult;

class SettingsController extends Controller
{
    public function __construct() {
        $this->middleware('auth:admins');

    }
    public function check()
    {
        $user = Auth::user();
        $check_admin = Admins::where('id', $user->id)->get();
        if($check_admin[0]->permission=='reseller')
        {
            exit(view('access'));
        }
    }
    public function defualt()
    {
        $this->check();
        return redirect()->intended(route('settings', ['name' => 'general']));
    }
    public function mod(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        if($name=='night' OR $name=='light')
        {
            Process::run("sed -i \"s/APP_MODE=.*/APP_MODE=$name/g\" /var/www/html/app/.env");
        }
        return redirect()->back()->with('success', 'success');
    }
    public function lang(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        if($name=='fa' OR $name=='en')
        {
            Process::run("sed -i \"s/APP_LOCALE=.*/APP_LOCALE=$name/g\" /var/www/html/app/.env");
        }

        return redirect()->back()->with('success', 'success');
    }
    public function index(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        $setting = Settings::all();
        $apis =Api::all();
        if($name=='general') {
            $status=$setting[0]->multiuser;
            $traffic_base=env('TRAFFIC_BASE');
            return view('settings.general', compact('traffic_base','status'));}


        if($name=='backup') {
            $list = Process::run("ls /var/www/html/app/storage/backup");
            $output = $list->output();
            $backuplist = preg_split("/\r\n|\n|\r/", $output);
            $lists=$backuplist;
            return view('settings.backup', compact('lists'));
        }
        if($name=='api') {
            $apis=$apis;
            return view('settings.api', compact('apis'));}
        if($name=='block') {
            $check_status = Process::run("sudo iptables -L OUTPUT");
            $output = $check_status->output();
            $output = preg_split("/\r\n|\n|\r/", $output);
            $output = count($output) - 3;
            $status=$output;
            return view('settings.block', compact('status'));
        }
        if($name=='fakeaddress') {return view('settings.fake');}
        if($name=='wordpress') {
            $protocol = isset($_SERVER['HTTPS']) ? 'https' : 'http';
            $http_host=$_SERVER['HTTP_HOST'];
            $output=$http_host.'/';
            $output=explode(':',$output);
            $output=$protocol.'://'.$output[0];
            $address=$output;
            return view('settings.wordpress', compact('address'));
        }

    }

    public function update_general(Request $request)
    {
        $this->check();
        $request->validate([
            'trafficbase'=>'required|numeric',
            'direct_login'=>'required|string',
            'lang'=>'required|string',
            'mode'=>'required|string',
            'status_traffic'=>'string',
            'status_multiuser'=>'string',
            'status_day'=>'string',
            'status_log'=>'string',
        ]);
        $traffic_base_old=env('TRAFFIC_BASE');
        $traffic_base_new=$request->trafficbase;
        $fileContents = file_get_contents('/var/www/html/app/.env');
        $newContents = str_replace("TRAFFIC_BASE=".$traffic_base_old, "TRAFFIC_BASE=".$traffic_base_new, $fileContents);
        file_put_contents('/var/www/html/app/.env', $newContents);
        if($request->lang=='fa' OR $request->lang=='en')
        {
            Process::run("sed -i \"s/APP_LOCALE=.*/APP_LOCALE=$request->lang/g\" /var/www/html/app/.env");
        }
        if($request->mode=='night' OR $request->mode=='light')
        {
            Process::run("sed -i \"s/APP_MODE=.*/APP_MODE=$request->mode/g\" /var/www/html/app/.env");
        }

        Process::run("sed -i \"s/PANEL_DIRECT=.*/PANEL_DIRECT=$request->direct_login/g\" /var/www/html/app/.env");
        if (empty($request->status_day) or $request->status_day=='deactive')
        {
            $status_day='deactive';
        }
        else
        {
            $status_day='active';
        }

        if (empty($request->status_traffic) or $request->status_traffic=='deactive')
        {
            $status_traffic='deactive';
        }
        else
        {
            $status_traffic='active';
        }

        if (empty($request->status_multiuser) or $request->status_multiuser=='deactive')
        {
            $status_multiuser='deactive';
        }
        else
        {
            $status_multiuser='active';
        }

        if (empty($request->status_log) or $request->status_log=='deactive')
        {
            $status_log='deactive';
        }
        else
        {
            $status_log='active';
        }
        Process::run("sed -i \"s/STATUS_LOG=.*/STATUS_LOG=$status_log/g\" /var/www/html/app/.env");
        Process::run("sed -i \"s/CRON_TRAFFIC=.*/CRON_TRAFFIC=$status_traffic/g\" /var/www/html/app/.env");
        Process::run("sed -i \"s/DAY=.*/DAY=$status_day/g\" /var/www/html/app/.env");
        $check_setting = Settings::where('id', '1')->count();
        if ($check_setting > 0) {
            Settings::where('id', 1)->update(['multiuser' => $status_multiuser]);
        }

        return redirect()->intended(route('settings', ['name' => 'general']));
    }

    public function update_telegram(Request $request)
    {
        $this->check();
        $request->validate([
            'tokenbot'=>'required|string',
            'idtelegram'=>'required|string'
        ]);
        $check_setting = Settings::where('id','1')->count();
        if ($check_setting > 0) {
            Settings::where('id', 1)->update(['t_token' => $request->tokenbot,'t_id' => $request->idtelegram]);
        } else {
            Settings::create([
                't_token' => $request->tokenbot,'t_id' => $request->idtelegram
            ]);
        }
        return redirect()->intended(route('settings', ['name' => 'telegram']));
    }

    public function upload_backup(Request $request)
    {
        $this->check();
        $request->validate([
            'file'=>'required|mimetypes:text/plain'
        ]);
        if($request->file('file')) {
            $file = $request->file('file');
            $filename = $file->getClientOriginalName();
            $file->move('/var/www/html/app/storage/backup/', $filename);

        }
        return redirect()->intended(route('settings', ['name' => 'backup']));
    }

    public function delete_backup(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        Process::run("rm -rf /var/www/html/app/storage/backup/".$name);
        return redirect()->intended(route('settings', ['name' => 'backup']));

    }

    public function restore_backup(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        Process::run("mysql -u '" . env('DB_USERNAME') . "' --password='" . env('DB_PASSWORD') . "' XPanel_plus < /var/www/html/app/storage/backup/" . $name);
        $users = Users::where('status', 'active')->get();
        $batchSize = 10;
        $userBatches = array_chunk($users->toArray(), $batchSize);

        foreach ($userBatches as $userBatch) {
            foreach ($userBatch as $user) {
                $username=$user['username'];
                $password=$user['password'];
                Process::run("sudo adduser --disabled-password --gecos '' --shell /usr/sbin/nologin {$username}");
                Process::input($password. "\n" .$password. "\n")->timeout(120)->run("sudo passwd {$username}");
                $check_traffic = Traffic::where('username', $username)->count();
                if ($check_traffic < 1) {
                    Traffic::create([
                        'username' => $username,
                        'download' => '0',
                        'upload' => '0',
                        'total' => '0'
                    ]);
                }
            }
        }
        return redirect()->intended(route('settings', ['name' => 'backup']));

    }

    public function make_backup()
    {
        $this->check();
        $date = date("Y-m-d---h-i-s");
        Process::run("mysqldump -u '" .env('DB_USERNAME'). "' --password='" .env('DB_PASSWORD'). "' XPanel_plus > /var/www/html/app/storage/backup/XPanel-".$date.".sql");
        return redirect()->intended(route('settings', ['name' => 'backup']));
    }
    public function download_backup(Request $request,$name)
    {
        $this->check();
        if (!is_string($name)) {
            abort(400, 'Not Valid Username');
        }
        $fileName = $name;
        $filePath = storage_path('backup/'.$fileName);

        if (file_exists('/var/www/html/app/storage/backup/'.$fileName)) {
            return response()->download($filePath, $fileName, [
                'Content-Type' => 'text/plain',
                'Content-Disposition' => 'attachment',
            ])->deleteFileAfterSend(true);
        }

        abort(404);
        return redirect()->intended(route('settings', ['name' => 'backup']));
    }

    public function insert_api(Request $request)
    {
        $this->check();
        $user = Auth::user();
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890";
        $token = substr(str_shuffle($chars), 0, 15);
        $request->validate([
            'desc'=>'required|string',
            'allowip'=>'required|string'
        ]);
        Api::create([
            'username' => $user->username,
            'token' => time().$token,
            'description' => $request->desc,
            'allow_ip' => $request->allowip,
            'status' => 'active'
        ]);
        return redirect()->intended(route('settings', ['name' => 'api']));
    }

    public function renew_api(Request $request,$id)
    {
        $this->check();
        if (!is_numeric($id)) {
            abort(400, 'Not Valid Username');
        }
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890";
        $token_new = substr(str_shuffle($chars), 0, 15);
        Api::where('id', $id)->update(['token' => time().$token_new]);
        return redirect()->intended(route('settings', ['name' => 'api']));
    }

    public function delete_api(Request $request,$id)
    {
        $this->check();
        if (!is_numeric($id)) {
            abort(400, 'Not Valid Username');
        }
        Api::where('id', $id)->delete();
        return redirect()->intended(route('settings', ['name' => 'api']));
    }

    public function block(Request $request)
    {
        $this->check();
        $request->validate([
            'status'=>'required|string'
        ]);
        if($request->status=='active')
        {
            Process::run("sudo iptables -A OUTPUT -m geoip -p tcp --destination-port 80 --dst-cc IR -j DROP");
            Process::run("sudo iptables -A OUTPUT -m geoip -p tcp --destination-port 443 --dst-cc IR -j DROP");
        }
        else
        {
            Process::run("sudo iptables -F");

        }

        return redirect()->intended(route('settings', ['name' => 'block']));
    }

    public function fakeurl(Request $request)
    {
        $this->check();
        $request->validate([
            'fake_address'=>'required|string'
        ]);
        $txt = '
<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
function curl_get_contents($url) {
    $ch = curl_init();
    $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,font/woff,font/woff2,";
    $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5,application/font-woff,*";
    $header[] = "Access-Control-Allow-Origin: *";
    $header[] = "Connection: keep-alive";
    $header[] = "Keep-Alive: 300";
    $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    $header[] = "Accept-Language: en-us,en;q=0.5";
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $header );

    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);

    // I have added below two lines
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $data = curl_exec($ch);
    curl_close($ch);

    return $data;
}
$site = "' . $request->fake_address . '";
echo curl_get_contents("$site");
        ';
        file_put_contents("/var/www/html/example/index.php", $txt);
        return redirect()->intended(route('settings', ['name' => 'fakeaddress']));
    }



}
