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
        try {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('tempDir', $temporary);
            $options->set('fontCache', $temporary);
            $options->set('chroot', resource_path('branding'));
            $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('branding/upgrade79-logo.png')));
            $pdf = new Dompdf($options);
            $pdf->setPaper('A4');
            $pdf->loadHtml(view('documents.commercial', compact('document', 'logo'))->render(), 'UTF-8');
            $pdf->render();
            $pdf->getCanvas()->page_text(36, 815, 'Upgrade La 79 · '.$document['code'].' · {PAGE_NUM}/{PAGE_COUNT}',
                $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.4, 0.4, 0.4]);

            return $pdf->output();
        } finally {
            (new Filesystem)->deleteDirectory($temporary);
        }
    }
}
