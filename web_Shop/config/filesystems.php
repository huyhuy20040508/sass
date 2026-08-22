<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            // GHI THẲNG vào thư mục đang được phục vụ, không đi qua storage/app/public.
            //
            // Mặc định của Laravel là storage_path('app/public') cộng một liên kết
            // tượng trưng public/storage -> đó. Trên máy này liên kết ấy KHÔNG tồn
            // tại: public/storage là một thư mục thật, và ảnh của sản phẩm, danh
            // mục, banner đều nằm sẵn trong đó. Hệ quả là mọi ảnh MỚI tải lên rơi
            // vào storage/app/public rồi nằm im — /storage/... trả 403 trong khi
            // ảnh cũ vẫn 200. Lỗi im lặng: tải lên báo thành công, ảnh không hiện.
            //
            // Trỏ thẳng root vào public_path('storage') thì đúng ở CẢ HAI kiểu cài:
            // máy này ghi vào thư mục thật, còn máy chủ có liên kết tượng trưng thì
            // ghi xuyên qua liên kết vào đúng storage/app/public như cũ.
            //
            // (Cách kia là chạy `php artisan storage:link`, nhưng nó đòi xoá thư mục
            // thật đang chứa ảnh của cửa hàng — và trên Windows thì lệnh tạo liên
            // kết còn cần quyền quản trị.)
            'root' => public_path('storage'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
