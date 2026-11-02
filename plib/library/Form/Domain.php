<?php
// Acciones sobre un dominio. Va por formulario para llevar la protección CSRF de Plesk.
class Modules_DondominioDns_Form_Domain extends pm_Form_Simple
{
    public const ACTIONS = ['preview', 'import', 'import_all', 'enable', 'disable', 'force'];

    public function __construct(private array $domains, $options = null)
    {
        parent::__construct($options);
    }

    public function init()
    {
        parent::init();
        $this->addElement('select', 'domain', [
            'label' => pm_Locale::lmsg('domainLabel'),
            'multiOptions' => array_combine($this->domains, $this->domains),
            'required' => true,
        ]);
        $actions = [];
        foreach (self::ACTIONS as $a) {
            $actions[$a] = pm_Locale::lmsg('action_' . $a);
        }
        $this->addElement('radio', 'operation', [
            'label' => pm_Locale::lmsg('operationLabel'),
            'multiOptions' => $actions,
            'value' => 'preview',
            'required' => true,
        ]);
        $this->addControlButtons(['cancelHidden' => true, 'sendTitle' => pm_Locale::lmsg('runButton')]);
    }
}
