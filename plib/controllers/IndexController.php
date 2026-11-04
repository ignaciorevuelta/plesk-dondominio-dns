<?php
class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();
        if (!pm_Session::getClient()->isAdmin()) {
            throw new pm_Exception('Permission denied');
        }
        $this->view->pageTitle = $this->lmsg('pageTitle');
        // Hasta aceptar el EULA no se muestra nada más ni se escribe en ningún sitio.
        if (!Modules_DondominioDns_Config::eulaAccepted() && $this->getRequest()->getActionName() !== 'eula') {
            $this->_redirect('index/eula');
        }
        $this->view->tabs = [
            ['title' => $this->lmsg('settingsTab'), 'action' => 'index'],
            ['title' => $this->lmsg('domainsTab'), 'action' => 'domains'],
        ];
    }

    public function eulaAction()
    {
        $form = new pm_Form_Simple();
        $form->addElement('checkbox', 'accept', [
            'label' => $this->lmsg('eulaAccept'),
            'required' => true,
            'uncheckedValue' => null,
        ]);
        $form->addControlButtons(['cancelLink' => pm_Context::getModulesListUrl()]);
        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            Modules_DondominioDns_Config::setEulaAccepted((string)pm_Session::getClient()->getProperty('login'));
            $this->_helper->json(['redirect' => pm_Context::getActionUrl('index', 'index')]);
        }
        $this->view->eula = file_get_contents(pm_Context::getPlibDir() . 'resources/EULA.md');
        $this->view->form = $form;
        $this->view->pageTitle = $this->lmsg('eulaTitle');
        $this->view->tabs = [];
    }

    public function indexAction()
    {
        $form = new Modules_DondominioDns_Form_Settings();
        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            try {
                $report = $form->process();
                $this->_status->addInfo($this->lmsg($report === null ? 'settingsSaved' : 'importDone', [
                    'imported' => count($report['imported'] ?? []),
                    'errors' => count($report['errors'] ?? []),
                ]));
            } catch (Exception $e) {
                $this->_status->addError($e->getMessage());
            }
            $this->_helper->json(['redirect' => pm_Context::getActionUrl('index', 'domains')]);
        }
        $this->view->form = $form;
        $current = Modules_DondominioDns_Backend::current();
        $this->view->backendStatus = Modules_DondominioDns_Backend::isOurs($current) ? 'backendOurs'
            : ($current === '' ? 'backendNone' : ($current === null ? 'backendUnknown' : 'backendOther'));
        $this->view->backendCurrent = (string)$current;
    }

    // Registrar la extensión como backend DNS cuando no lo es: decisión expresa, con aviso si sustituye a otro.
    public function backendAction()
    {
        $current = Modules_DondominioDns_Backend::current();
        $form = new pm_Form_Simple();
        $form->addControlButtons(['cancelLink' => pm_Context::getActionUrl('index', 'index'), 'sendTitle' => $this->lmsg('backendUse')]);
        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            try {
                Modules_DondominioDns_Backend::register();
                $this->_status->addInfo($this->lmsg('backendRegistered'));
            } catch (Exception $e) {
                $this->_status->addError($e->getMessage());
            }
            $this->_helper->json(['redirect' => pm_Context::getActionUrl('index', 'index')]);
        }
        $this->view->form = $form;
        $this->view->current = Modules_DondominioDns_Backend::isOurs($current) ? '' : ($current ?? $this->lmsg('backendUnknown'));
        $this->view->pageTitle = $this->lmsg('backendUse');
    }

    public function domainsAction()
    {
        $domains = $this->configured() ? (new Modules_DondominioDns_PleskDns())->domains() : [];
        $form = new Modules_DondominioDns_Form_Domain($domains);
        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            $domain = $form->getValue('domain');
            $operation = $form->getValue('operation');
            $redirect = pm_Context::getActionUrl('index', 'domains');
            try {
                if ($operation === 'preview') {
                    $redirect = $this->runOperation($operation, $domain);
                } elseif (!Modules_DondominioDns_Runner::locked(function () use ($operation, $domain, &$redirect) {
                    $redirect = $this->runOperation($operation, $domain) ?? $redirect;
                })) {
                    $this->_status->addError($this->lmsg('busy'));
                }
            } catch (Modules_DondominioDns_BlockedException $e) {
                $this->_status->addError($this->lmsg('blocked', ['error' => $e->getMessage()]));
            } catch (Exception $e) {
                $this->_status->addError($e->getMessage());
            }
            $this->_helper->json(['redirect' => $redirect]);
        }
        $this->view->form = $form;
        $this->view->list = $this->domainList($domains);
        $this->view->report = Modules_DondominioDns_Config::report();
        $this->view->configured = $this->configured();
    }

    public function importAllAction()
    {
        $form = new pm_Form_Simple();
        $form->addControlButtons(['cancelLink' => pm_Context::getActionUrl('index', 'domains'), 'sendTitle' => $this->lmsg('importAllButton')]);
        if (!$this->getRequest()->isPost() || !$form->isValid($this->getRequest()->getPost())) {
            $this->view->form = $form;
            $this->view->pageTitle = $this->lmsg('importAllButton');
            return;
        }
        try {
            $ran = Modules_DondominioDns_Runner::locked(function () {
                $report = Modules_DondominioDns_Runner::importer()->importAll();
                Modules_DondominioDns_Config::setReport($report);
                $this->_status->addInfo($this->lmsg('importAllDone', ['imported' => count($report['imported']), 'errors' => count($report['errors'])]));
            });
            if (!$ran) {
                $this->_status->addError($this->lmsg('busy'));
            }
        } catch (Exception $e) {
            $this->_status->addError($e->getMessage());
        }
        $this->_helper->json(['redirect' => pm_Context::getActionUrl('index', 'domains')]);
    }

    public function previewAction()
    {
        $domain = $this->domainParam();
        $this->view->list = $this->previewList($domain);
        $this->view->pageTitle = $this->lmsg('previewTitle', ['domain' => $domain]);
    }

    public function previewDataAction()
    {
        $this->_helper->json($this->previewList($this->domainParam())->fetchData());
    }

    public function domainsDataAction()
    {
        $domains = $this->configured() ? (new Modules_DondominioDns_PleskDns())->domains() : [];
        $this->_helper->json($this->domainList($domains)->fetchData());
    }

    private function domainParam(): string
    {
        $domain = (string)$this->getRequest()->getParam('domain');
        if (!in_array($domain, (new Modules_DondominioDns_PleskDns())->domains(), true)) {
            throw new pm_Exception('Unknown domain');
        }
        return $domain;
    }

    private function previewList(string $domain): pm_View_List_Simple
    {
        $plan = Modules_DondominioDns_Runner::preview($domain);
        $rows = [];
        foreach (['create' => $plan['create'], 'delete' => $plan['delete'], 'foreign' => $plan['foreign'], 'skipped' => $plan['skipped']] as $op => $recs) {
            foreach ($recs as $r) {
                $rows[] = $this->row($op, $r);
            }
        }
        foreach ($plan['update'] as $u) {
            $rows[] = $this->row('update', $u['record']);
        }
        $list = new pm_View_List_Simple($this->view, $this->_request);
        $list->setData($rows);
        $list->setColumns([
            'change' => ['title' => $this->lmsg('changeColumn')],
            'name' => ['title' => $this->lmsg('nameColumn')],
            'type' => ['title' => $this->lmsg('typeColumn')],
            'value' => ['title' => $this->lmsg('valueColumn')],
        ]);
        $list->setDataUrl(['action' => 'preview-data', 'domain' => $domain]);
        return $list;
    }

    private function runOperation(string $operation, string $domain): ?string
    {
        switch ($operation) {
            case 'preview':
                return pm_Context::getActionUrl('index', 'preview') . '?domain=' . rawurlencode($domain);
            case 'import':
                $result = Modules_DondominioDns_Runner::importer()->importOne($domain);
                $this->_status->addInfo($this->lmsg('imported', ['domain' => $domain]));
                if ($result['kept']) {
                    $this->_status->addWarning($this->lmsg('importKept', ['domain' => $domain, 'records' => implode('; ', $result['kept'])]));
                }
                return null;
            case 'enable':
                Modules_DondominioDns_Runner::syncDomain($domain, false, true);
                Modules_DondominioDns_Config::setEnabled(array_merge(Modules_DondominioDns_Config::enabled(), [$domain]));
                $this->_status->addInfo($this->lmsg('enabled', ['domain' => $domain]));
                return null;
            case 'disable':
                Modules_DondominioDns_Config::setEnabled(array_diff(Modules_DondominioDns_Config::enabled(), [$domain]));
                Modules_DondominioDns_Runner::forget($domain);
                Modules_DondominioDns_Config::setKnown($domain, null);
                $this->_status->addInfo($this->lmsg('disabled', ['domain' => $domain]));
                return null;
            case 'force':
                // También sirve para la primera sincronización retenida al activar: aplica y deja el dominio activado.
                Modules_DondominioDns_Runner::syncDomain($domain, true, true);
                Modules_DondominioDns_Config::setEnabled(array_merge(Modules_DondominioDns_Config::enabled(), [$domain]));
                Modules_DondominioDns_Runner::forget($domain);
                $this->_status->addInfo($this->lmsg('forced', ['domain' => $domain]));
                return null;
        }
        throw new pm_Exception('Unknown operation');
    }

    private function domainList(array $domains): pm_View_List_Simple
    {
        $enabled = Modules_DondominioDns_Config::enabled();
        $failures = Modules_DondominioDns_Config::failures();
        $notices = Modules_DondominioDns_Config::notices();
        $rows = [];
        foreach ($domains as $d) {
            $f = $failures[$d] ?? null;
            $status = !in_array($d, $enabled, true) ? 'statusOff' : ($f === null ? 'statusOn' : (!empty($f['blocked']) ? 'statusBlocked' : 'statusFailing'));
            $warnings = [];
            if (!empty($notices[$d]['foreign'])) {
                $warnings[] = $this->lmsg('noticeForeign', ['records' => implode('; ', $notices[$d]['foreign'])]);
            }
            if (!empty($notices[$d]['skipped'])) {
                $warnings[] = $this->lmsg('noticeSkipped', ['records' => implode('; ', $notices[$d]['skipped'])]);
            }
            $rows[] = ['domain' => $d, 'status' => $this->lmsg($status), 'error' => implode(' ', array_filter([$f['error'] ?? '', ...$warnings]))];
        }
        $list = new pm_View_List_Simple($this->view, $this->_request);
        $list->setData($rows);
        $list->setColumns([
            'domain' => ['title' => $this->lmsg('domainLabel')],
            'status' => ['title' => $this->lmsg('statusColumn')],
            'error' => ['title' => $this->lmsg('errorColumn')],
        ]);
        $list->setTools([[
            'title' => $this->lmsg('importAllButton'),
            'description' => $this->lmsg('importAllHint'),
            'link' => pm_Context::getActionUrl('index', 'import-all'),
        ]]);
        $list->setDataUrl(['action' => 'domains-data']);
        return $list;
    }

    private function row(string $op, array $r): array
    {
        return ['change' => $this->lmsg('change_' . $op), 'name' => $r['name'], 'type' => $r['type'], 'value' => ($r['priority'] !== '-' ? $r['priority'] . ' ' : '') . $r['value']];
    }

    private function configured(): bool
    {
        return Modules_DondominioDns_Config::user() !== '' && Modules_DondominioDns_Config::pass() !== '';
    }
}
