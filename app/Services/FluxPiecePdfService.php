<?php

namespace App\Services;

use App\Models\FluxPieceCommerciale;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

class FluxPiecePdfService
{
    public function telecharger(FluxPieceCommerciale $piece): Response
    {
        $piece->loadMissing(['lignes', 'antenne']);

        $titre = $piece->type === FluxPieceCommerciale::TYPE_FACTURE
            ? 'Facture OPN'
            : 'Bon de commande OPN';

        $safeNumero = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($piece->numero ?: 'DOC')) ?: 'DOC';
        $filename = $safeNumero . '.pdf';

        $html = View::make('pdf.flux-piece', [
            'piece' => $piece,
            'titre' => $titre,
            'lignes' => $piece->lignes ?? collect(),
        ])->render();

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot(base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->setCallbacks([
            [
                'event' => 'begin_page_render',
                'f' => function ($frame, $canvas, $fontMetrics) {
                    self::dessinerFiligrane($canvas, $fontMetrics);
                },
            ],
        ]);
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /** Filigrane gris derrière le texte : petit, serré, sur toute la page y compris les bords. */
    private static function dessinerFiligrane($canvas, $fontMetrics): void
    {
        $font = $fontMetrics->getFont('DejaVu Sans', 'bold');
        $largeur = $canvas->get_width();
        $hauteur = $canvas->get_height();
        $texte = str_repeat('OPN', 140);
        $gris = [0.47, 0.47, 0.47];
        $pas = 12;
        $angle = -28.0;
        $marge = max($largeur, $hauteur) * 2;
        $canvas->save();
        for ($y = -$marge, $i = 0; $y <= $hauteur + $marge; $y += $pas, $i++) {
            $x = -$marge + (($i % 2) * 8);
            $canvas->set_opacity(0.08);
            $canvas->text($x + 0.6, $y + 0.6, $texte, $font, 8, $gris, 0.0, 0.04, $angle);
            $canvas->set_opacity(0.14);
            $canvas->text($x, $y, $texte, $font, 8, $gris, 0.0, 0.04, $angle);
        }
        $canvas->restore();
        $canvas->set_opacity(1);
    }
}
