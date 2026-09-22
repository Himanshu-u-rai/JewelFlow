<?php

namespace App\Console\Commands;

use App\Models\StockPurchase;

/**
 * S3-03 remediation, part two — move purchase invoice images off the
 * web-served public disk.
 *
 * The same procedure, safety contract and failure behaviour as
 * RelocateKarigarInvoiceAttachments (read that docblock), pointed at
 * stock_purchases. StockPurchaseController::showInvoiceImage() already reads
 * the disk each row records, so every intermediate state still serves.
 *
 * No trigger guards stock_purchases, and the only constraint on the column
 * (stock_purchases_invoice_image_disk_check) allows 'public' and 'local'.
 */
class RelocatePurchaseInvoiceImages extends RelocateKarigarInvoiceAttachments
{
    protected $signature = 'purchases:relocate-invoice-images
        {--execute : Perform writes. Without this flag the command is a dry run and changes nothing.}
        {--shop= : Restrict to a single shop_id.}
        {--limit= : Process at most N rows in this run.}
        {--verify : Verify already-relocated rows instead of relocating any more.}
        {--purge-originals : Delete public-disk originals whose private copy verifies. Requires --execute to actually delete.}';

    protected $description = 'Move purchase invoice images from the web-served public disk to the private disk (dry run unless --execute).';

    protected const TABLE = 'stock_purchases';

    protected const PATH_COLUMN = 'invoice_image';

    protected const DISK_COLUMN = 'invoice_image_disk';

    protected const MANIFEST_PREFIX = 'purchase-invoice-images';

    protected function targetDisk(): string
    {
        return StockPurchase::ATTACHMENT_DISK;
    }
}
