<?php
class Modules_DondominioDns_Form_Settings extends pm_Form_Simple
{
    public function init()
    {
        parent::init();
        $this->addElement('text', 'user', [
            'label' => pm_Locale::lmsg('userLabel'),
            'value' => Modules_DondominioDns_Config::user(),
            'required' => true,
            'filters' => ['StringTrim'],
        ]);
        // La contraseña guardada nunca se envía al navegador; vacío = conservarla.
        $this->addElement('password', 'pass', [
            'label' => pm_Locale::lmsg('passLabel'),
            'description' => Modules_DondominioDns_Config::pass() === '' ? '' : pm_Locale::lmsg('passKeepHint'),
            'required' => Modules_DondominioDns_Config::pass() === '',
        ]);
        $this->addElement('text', 'threshold', [
            'label' => pm_Locale::lmsg('thresholdLabel'),
            'description' => pm_Locale::lmsg('thresholdHint'),
            'value' => Modules_DondominioDns_Config::threshold(),
            'required' => true,
            'validators' => ['Int', ['Between', false, ['min' => 0, 'max' => 100]]],
        ]);
        $this->addControlButtons(['cancelLink' => pm_Context::getModulesListUrl()]);
    }

    public function isValid($data)
    {
        if (!parent::isValid($data)) {
            return false;
        }
        $pass = $this->getValue('pass') ?: Modules_DondominioDns_Config::pass();
        try {
            (new Modules_DondominioDns_DonDominioClient($this->getValue('user'), $pass))->listServices();
        } catch (Exception $e) {
            $this->getElement('user')->addError(pm_Locale::lmsg('credentialsInvalid', ['error' => $e->getMessage()]));
            $this->markAsError();
            return false;
        }
        return true;
    }

    // Devuelve el informe de la importación inicial si se ha ejecutado ahora.
    public function process(): ?array
    {
        Modules_DondominioDns_Config::setCredentials($this->getValue('user'), $this->getValue('pass') ?: Modules_DondominioDns_Config::pass());
        Modules_DondominioDns_Config::setThreshold((int)$this->getValue('threshold'));
        if (Modules_DondominioDns_Config::importDone()) {
            return null;
        }
        $report = Modules_DondominioDns_Runner::importer()->importAll();
        Modules_DondominioDns_Config::setReport($report);
        Modules_DondominioDns_Config::setImportDone();
        return $report;
    }
}
