<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CreateExcelTemplate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'template:excel {--output=public/templates/transaction_template.xlsx}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '创建交易数据导入的Excel模板';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('创建Excel模板...');
        
        // 创建Spreadsheet对象
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // 设置标题行 (使用中文字段名)
        $headers = [
            '币种', 
            '会员ID', 
            '会员账号', 
            '渠道ID',
            '注册来源', 
            '注册时间', 
            '总充提差额'
        ];
        
        // 样例数据
        $sampleData = [
            ['PKR', '12345', 'user1', 'CH001', '渠道1', '2025-03-25 10:00:00', '1000'],
            ['PKR', '12346', 'user2', 'CH001', '渠道1', '2025-03-25 11:30:00', '500'],
            ['PKR', '12347', 'user3', 'CH002', '渠道2', '2025-03-25 15:20:00', '1500'],
            ['USD', '12348', 'user4', 'CH003', '渠道3', '2025-03-25 09:15:00', '200'],
            ['USD', '12349', 'user5', 'CH003', '渠道3', '2025-03-25 14:45:00', '350'],
            ['PKR', '12350', 'user6', 'CH001', '渠道1', '2025-03-25 16:30:00', '800'],
            ['PKR', '12351', 'user7', 'CH002', '渠道2', '2025-03-25 17:20:00', '1200'],
            ['USD', '12352', 'user8', 'CH003', '渠道3', '2025-03-25 18:10:00', '150'],
            ['PKR', '12353', 'user9', 'CH001', '渠道1', '2025-03-25 19:05:00', '900'],
            ['USD', '12354', 'user10', 'CH002', '渠道2', '2025-03-25 20:00:00', '250'],
        ];
        
        // 写入标题行
        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }
        
        // 写入样例数据
        foreach ($sampleData as $rowIndex => $rowData) {
            foreach ($rowData as $columnIndex => $cellValue) {
                $sheet->setCellValueByColumnAndRow($columnIndex + 1, $rowIndex + 2, $cellValue);
            }
        }
        
        // 调整列宽
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        // 设置输出路径
        $outputPath = $this->option('output');
        
        // 检查并创建目录
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // 保存Excel文件
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
        
        $this->info("Excel模板已创建: {$outputPath}");
        
        return 0;
    }
}
