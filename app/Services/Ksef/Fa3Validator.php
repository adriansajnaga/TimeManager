<?php

namespace App\Services\Ksef;

use DOMDocument;

/**
 * Walidacja XML faktury względem schematu FA(3) zapisanego w resources/ksef/fa3
 * (pliki z crd.gov.pl z lokalnymi odwołaniami), zanim dokument pójdzie do KSeF.
 */
class Fa3Validator
{
    /**
     * @return list<string> błędy (pusta lista = poprawny)
     */
    public function errors(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument;
        $valid = $xml !== ''
            && $document->loadXML($xml)
            && $document->schemaValidate(resource_path('ksef/fa3/schemat.xsd'));

        $errors = array_values(array_unique(array_map(
            fn (\LibXMLError $error) => trim($error->message),
            libxml_get_errors(),
        )));

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid ? [] : ($errors ?: ['Invalid XML']);
    }

    /**
     * @throws KsefException
     */
    public function assertValid(string $xml): void
    {
        $errors = $this->errors($xml);

        if ($errors !== []) {
            throw new KsefException(__('The invoice XML does not match the FA(3) schema: :errors', ['errors' => implode(' ', array_slice($errors, 0, 3))]));
        }
    }
}
