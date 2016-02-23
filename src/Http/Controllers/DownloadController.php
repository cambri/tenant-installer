<?php

namespace Hyn\Installer\Http\Controllers;

use App\Http\Controllers\Controller;

class DownloadController extends Controller {
    public function generate() {

        return view('installer.base', request()->all());
    }
}