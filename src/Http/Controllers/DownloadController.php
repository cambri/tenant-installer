<?php

namespace Hyn\Installer\Http\Controllers;

use App\Http\Controllers\Controller;

class DownloadController extends Controller {
    public function generate() {
        header('Content-Disposition: inline; filename="hyn-installer.sh"');
        return view('installer.base', request()->all());
    }
}