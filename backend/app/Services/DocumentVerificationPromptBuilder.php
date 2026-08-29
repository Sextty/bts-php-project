<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Document;
use DateTimeInterface;

/**
 * Builds the provider-neutral AI prompt for document verification: authenticity assessment plus, for
 * document types listed in config('credit_documents.ai_comparable_fields'), field extraction
 * and comparison against the applicant's Client (Étape 1) form data. Pure class — no I/O,
 * so it is trivially unit-testable.
 */
class DocumentVerificationPromptBuilder
{
    public function build(Document $document, Client $client): string
    {
        $documentType = $document->document_type;
        $typeLabel = config("credit_documents.types.{$documentType}.label", $documentType);
        $comparableFields = config("credit_documents.ai_comparable_fields.{$documentType}", []);
        $fieldLabels = config('credit_documents.ai_field_labels', []);

        $parts = [];
        $parts[] = "Analyse ce justificatif d'une demande de crédit BTS. Type déclaré : \"{$typeLabel}\". "
            .'Vérifie uniquement son authenticité, sa lisibilité et son intégrité, jamais la solvabilité du client. '
            .'Tous les textes produits doivent être en français, courts, clairs et professionnels.';

        $parts[] = 'AUTHENTICITÉ : signale toute falsification visible, capture d’écran, altération, '
            .'incohérence ou zone illisible. Si le contenu ne peut pas être lu, utilise la confiance "low" '
            .'et explique-le directement en français. Si le fichier ne correspond manifestement pas au type '
            .'déclaré (illustration, logo, fond d’écran ou autre mauvais justificatif), mets is_valid à false, '
            .'renvoie mismatches = [] et indique la cause exacte ainsi que le document à fournir.';

        if ($comparableFields) {
            $parts[] = 'EXTRACTION : relève seulement les champs visibles suivants ; utilise null si illisible :';

            foreach ($comparableFields as $field) {
                $parts[] = '  - '.$field.' ('.($fieldLabels[$field] ?? $field).')';
            }

            $parts[] = 'COMPARAISON : compare aux valeurs attendues ci-dessous. Ignore la casse et les espaces '
                .'superflus dans les noms ; compare les dates au format yyyy-mm-dd. Ajoute une divergence pour '
                .'chaque différence : "critical" pour identité, numéro ou date de naissance différents ; '
                .'"warning" pour un écart mineur de format. Ne compare les champs que si le fichier correspond '
                .'réellement au type déclaré et si les valeurs sont lisibles.';

            $parts[] = 'Valeurs attendues du formulaire :';
            foreach ($comparableFields as $field) {
                $parts[] = '  - '.$field.': "'.$this->clientValue($client, $field).'"';
            }
        } else {
            $parts[] = 'Aucune comparaison de champs : renvoie extracted_fields = {} et mismatches = [].';
        }

        $parts[] = 'Réponds UNIQUEMENT avec ce JSON exact, sans Markdown ni texte autour : '
            .'{"is_valid": boolean, "confidence": "high"|"medium"|"low", '
            .'"comment": "une phrase en français de 160 caractères maximum", '
            .'"extracted_fields": {"field_name": "value or null"}, '
            .'"mismatches": [{"field": "field_name", "expected": "value", "extracted": "value", '
            .'"severity": "critical"|"warning"}]}. '
            .'is_valid doit être false si le document paraît falsifié, altéré, capturé depuis un écran '
            .'ou contient une divergence "critical". Garde les clés et les valeurs techniques des enums '
            .'en anglais. Le commentaire doit être entièrement en français, parler directement au client, '
            .'indiquer la cause précise puis l’action à effectuer. N’utilise jamais les termes techniques '
            .'« IA », « is_valid », « confidence » ou « mismatch » dans le commentaire.';

        return implode(' ', $parts);
    }

    private function clientValue(Client $client, string $field): string
    {
        $value = $client->{$field};

        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
