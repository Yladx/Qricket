<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ViewInvoiceData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoice:view {type=all : Type of data to view (invoices, webhooks, all)} {--latest : Show only the latest file} {--file= : Show specific file by name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View saved invoice and webhook JSON data from local storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->argument('type');
        $showLatest = $this->option('latest');
        $specificFile = $this->option('file');

        if ($specificFile) {
            $this->showSpecificFile($specificFile);
            return 0;
        }

        switch ($type) {
            case 'invoices':
                $this->showInvoices($showLatest);
                break;
            case 'webhooks':
                $this->showWebhooks($showLatest);
                break;
            case 'all':
                $this->showInvoices($showLatest);
                $this->line('');
                $this->showWebhooks($showLatest);
                break;
            default:
                $this->error("Invalid type. Use 'invoices', 'webhooks', or 'all'");
                return 1;
        }

        return 0;
    }

    private function showInvoices($showLatest = false)
    {
        $invoicesDir = storage_path('app/invoices');
        
        if (!file_exists($invoicesDir)) {
            $this->warn('No invoices directory found.');
            return;
        }

        $files = File::files($invoicesDir);
        $invoiceFiles = array_filter($files, function($file) {
            return str_contains($file->getFilename(), 'invoice_');
        });

        if (empty($invoiceFiles)) {
            $this->warn('No invoice files found.');
            return;
        }

        // Sort by modification time (newest first)
        usort($invoiceFiles, function($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        if ($showLatest) {
            $invoiceFiles = array_slice($invoiceFiles, 0, 1);
        }

        $this->info('📄 Invoice Files:');
        $this->line('');

        foreach ($invoiceFiles as $file) {
            $this->showFileInfo($file, 'invoice');
        }
    }

    private function showWebhooks($showLatest = false)
    {
        $webhooksDir = storage_path('app/webhooks');
        
        if (!file_exists($webhooksDir)) {
            $this->warn('No webhooks directory found.');
            return;
        }

        $files = File::files($webhooksDir);
        $webhookFiles = array_filter($files, function($file) {
            return str_contains($file->getFilename(), 'webhook_');
        });

        if (empty($webhookFiles)) {
            $this->warn('No webhook files found.');
            return;
        }

        // Sort by modification time (newest first)
        usort($webhookFiles, function($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        if ($showLatest) {
            $webhookFiles = array_slice($webhookFiles, 0, 1);
        }

        $this->info('🔗 Webhook Files:');
        $this->line('');

        foreach ($webhookFiles as $file) {
            $this->showFileInfo($file, 'webhook');
        }
    }

    private function showFileInfo($file, $type)
    {
        $filename = $file->getFilename();
        $size = $file->getSize();
        $modified = date('Y-m-d H:i:s', $file->getMTime());
        
        $this->line("📁 {$filename}");
        $this->line("   Size: " . $this->formatBytes($size));
        $this->line("   Modified: {$modified}");
        
        // Try to read and show basic info from JSON
        try {
            $content = json_decode(file_get_contents($file->getPathname()), true);
            
            if ($type === 'invoice' && isset($content['invoice_data'])) {
                $invoiceData = $content['invoice_data'];
                $this->line("   Invoice ID: " . ($invoiceData['id'] ?? 'N/A'));
                $this->line("   Status: " . ($invoiceData['status'] ?? 'N/A'));
                $this->line("   Amount: " . ($invoiceData['amount'] ?? 'N/A'));
                $this->line("   User: " . ($content['user_info']['email'] ?? 'N/A'));
            }
            
            if ($type === 'webhook' && isset($content['webhook_data'])) {
                $webhookData = $content['webhook_data'];
                $this->line("   Status: " . ($webhookData['status'] ?? 'N/A'));
                $this->line("   Invoice ID: " . ($webhookData['id'] ?? 'N/A'));
                $this->line("   Payment ID: " . ($webhookData['payment_id'] ?? 'N/A'));
            }
            
        } catch (\Exception $e) {
            $this->line("   Error reading file: " . $e->getMessage());
        }
        
        $this->line('');
    }

    private function showSpecificFile($filename)
    {
        // Try to find the file in both directories
        $invoicesPath = storage_path('app/invoices/' . $filename);
        $webhooksPath = storage_path('app/webhooks/' . $filename);
        
        if (file_exists($invoicesPath)) {
            $this->showFileContent($invoicesPath, 'invoice');
        } elseif (file_exists($webhooksPath)) {
            $this->showFileContent($webhooksPath, 'webhook');
        } else {
            $this->error("File '{$filename}' not found in invoices or webhooks directories.");
        }
    }

    private function showFileContent($filepath, $type)
    {
        try {
            $content = file_get_contents($filepath);
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON file.');
                return;
            }
            
            $this->info("📄 File: " . basename($filepath));
            $this->line('');
            
            // Pretty print the JSON
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
        } catch (\Exception $e) {
            $this->error('Error reading file: ' . $e->getMessage());
        }
    }

    private function formatBytes($size, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, $precision) . ' ' . $units[$i];
    }
} 