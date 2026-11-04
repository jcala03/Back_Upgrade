<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class CommercialDocumentPdf
{
    public function render(array $document): string
    {
        // Only generated assets, private permissions and cleanup even on rendering failure.
        $temporary = sys_get_temp_dir().'/upgrade79-pdf-'.bin2hex(random_bytes(16));
        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('Cannot prepare private PDF workspace.');
        }
        $fonts = null;
        $rendered = false;
        try {
            $fonts = new CommercialPdfFontCache(storage_path('framework/cache/commercial-pdf-fonts'));
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('tempDir', $temporary);
            $options->set('fontCache', $fonts->acquire($options, $temporary));
            $options->set('chroot', resource_path('branding'));
            $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('branding/upgrade79-logo.png')));
            $pdf = $this->makeDompdf($options);
            $pdf->setPaper('A4');
            $pdf->loadHtml(view('documents.commercial', compact('document', 'logo'))->render(), 'UTF-8');
            $pdf->render();
            $pdf->getCanvas()->line(30, 805, 565, 805, [0.84, 0.84, 0.84], 0.5);
            $pdf->getCanvas()->page_text(30, 817, 'Upgrade La 79 · '.$document['code'].' · {PAGE_NUM}/{PAGE_COUNT}',
                $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7.5, [0.35, 0.35, 0.35]);

            $bytes = $pdf->output();
            $rendered = true;

            return $bytes;
        } finally {
            try {
                $fonts?->release($rendered);
            } finally {
                (new Filesystem)->deleteDirectory($temporary);
            }
        }
    }

    protected function makeDompdf(Options $options): Dompdf
    {
        return new Dompdf($options);
    }
}
