<?php
namespace Genkgo\Camt\Camt053;

use DateTimeImmutable;
use DOMDocument;
use Genkgo\Camt\Camt053\Iterator\EntryIterator;
use Genkgo\Camt\Exception\InvalidMessageException;
use Genkgo\Camt\Iban;
use Money\Currency;
use Money\Money;
use SimpleXMLElement;

/**
 * Class Message
 * @package Genkgo\Camt\Camt053
 */
class Message
{
    /**
     * @var SimpleXMLElement[]
     */
    private $document;
    /**
     * @var
     */
    private $groupHeader;
    /**
     * @var
     */
    private $statements;

    /**
     * @param DOMDocument $document
     * @throws InvalidMessageException
     */
    public function __construct(DOMDocument $document)
    {
        $this->validate($document);
        $this->document = simplexml_import_dom($document);
    }

    /**
     * @return GroupHeader
     */
    public function getGroupHeader()
    {
        if ($this->groupHeader === null) {
            $groupHeaderXml = $this->document->BkToCstmrStmt->GrpHdr;

            $this->groupHeader = new GroupHeader(
                (string) $groupHeaderXml->MsgId,
                new DateTimeImmutable((string) $groupHeaderXml->CreDtTm)
            );
        }

        return $this->groupHeader;
    }

    /**
     * @return Statement[]
     */
    public function getStatements()
    {
        if ($this->statements === null) {
            $this->statements = [];

            $statementsXml = $this->document->BkToCstmrStmt->Stmt;
            foreach ($statementsXml as $statementXml) {
                $statement = new Statement(
                    $statementXml->Id,
                    new DateTimeImmutable((string) $statementXml->CreDtTm),
                    new Account(new Iban((string) $statementXml->Acct->Id->IBAN))
                );

                $this->addBalancesToStatement($statementXml, $statement);
                $this->addEntriesToStatement($statementXml, $statement);

                $this->statements[] = $statement;
            }
        }

        return $this->statements;
    }

    /**
     * @return EntryIterator|Entry[]
     */
    public function getEntries()
    {
        return new EntryIterator($this);
    }

    /**
     * @param SimpleXMLElement $statementXml
     * @param Statement $statement
     */
    private function addBalancesToStatement(SimpleXMLElement $statementXml, Statement $statement)
    {
        $balancesXml = $statementXml->Bal;
        foreach ($balancesXml as $balanceXml) {
            $amount = Money::stringToUnits((string)$balanceXml->Amt);
            $currency = (string)$balanceXml->Amt['Ccy'];
            $date = (string)$balanceXml->Dt->Dt;

            if ((string) $balanceXml->CdtDbtInd === 'DBIT') {
                $amount = $amount * -1;
            }

            if ((string) $balanceXml->Tp->CdOrPrtry->Cd === 'OPBD') {
                $balance = Balance::opening(
                    new Money(
                        $amount,
                        new Currency($currency)
                    ),
                    new DateTimeImmutable($date)
                );
            } else {
                $balance = Balance::closing(
                    new Money(
                        $amount,
                        new Currency($currency)
                    ),
                    new DateTimeImmutable($date)
                );
            }

            $statement->addBalance($balance);
        }
    }

    /**
     * @param SimpleXMLElement $statementXml
     * @param Statement $statement
     */
    private function addEntriesToStatement(SimpleXMLElement $statementXml, Statement $statement)
    {
        $entriesXml = $statementXml->Ntry;
        foreach ($entriesXml as $entryXml) {
            $amount = Money::stringToUnits((string) $entryXml->Amt);
            $currency = (string)$entryXml->Amt['Ccy'];
            $bookingDate = (string)$entryXml->BookgDt->Dt;
            $valueDate = (string)$entryXml->ValDt->Dt;

            if ((string) $entryXml->CdtDbtInd === 'DBIT') {
                $amount = $amount * -1;
            }

            $entry = new Entry(
                new Money($amount, new Currency($currency)),
                new DateTimeImmutable($bookingDate),
                new DateTimeImmutable($valueDate)
            );

            $this->addTransactionDetailsToEntry($entryXml, $entry);

            $statement->addEntry($entry);
        }
    }

    /**
     * @param DOMDocument $document
     * @throws InvalidMessageException
     */
    private function validate(DOMDocument $document)
    {
        libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(dirname(dirname(__DIR__)).'/assets/camt.053.001.02.xsd');
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if (!$valid) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[] = $error->message;
            }

            $errorMessage = implode("\n", $messages);
            throw new InvalidMessageException("Provided XML is not valid according to the XSD:\n{$errorMessage}");
        }
    }

    /**
     * @param SimpleXMLElement $entryXml
     * @param Entry $entry
     */
    private function addTransactionDetailsToEntry(SimpleXMLElement $entryXml, Entry $entry)
    {
        $detailsXml = $entryXml->NtryDtls->TxDtls;
        foreach ($detailsXml as $detailXml) {
            $detail = new EntryTransactionDetail();
            if (isset($detailXml->Refs->EndToEndId)) {
                $endToEndId = (string)$detailXml->Refs->EndToEndId;
                if (isset($detailXml->Refs->MndtId)) {
                    $mandateId = (string)$detailXml->Refs->MndtId;
                } else {
                    $mandateId = null;
                }
                $detail->addReference(new Reference($endToEndId, $mandateId));
            }

            if (isset($detailXml->RltdPties)) {
                foreach ($detailXml->RltdPties as $relatedPartyXml) {
                    $creditor = new Creditor((string)$relatedPartyXml->Cdtr->Nm);
                    if (isset($relatedPartyXml->Cdtr->PstlAdr)) {
                        $address = new Address();
                        if (isset($relatedPartyXml->Cdtr->PstlAdr->Ctry)) {
                            $address = $address->setCountry($relatedPartyXml->Cdtr->PstlAdr->Ctry);
                        }
                        if (isset($relatedPartyXml->Cdtr->PstlAdr->AdrLine)) {
                            foreach ($relatedPartyXml->Cdtr->PstlAdr->AdrLine as $line) {
                                $address = $address->addAddressLine((string)$line);
                            }
                        }

                        $creditor->setAddress($address);
                    }

                    $account = new Account(new Iban((string)$relatedPartyXml->CdtrAcct->Id->IBAN));
                    $relatedParty = new RelatedParty($creditor, $account);
                    $detail->addRelatedParty($relatedParty);
                }
            }

            if (isset($detailXml->RmtInf)) {
                if (isset($detailXml->RmtInf->Ustrd)) {
                    $remittanceInformation = RemittanceInformation::fromUnstructured(
                        (string)$detailXml->RmtInf->Ustrd
                    );
                    $detail->setRemittanceInformation($remittanceInformation);
                }
            }

            $entry->addTransactionDetail($detail);
        }
    }
}