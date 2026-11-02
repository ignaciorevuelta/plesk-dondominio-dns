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
        $this->view->tabs = [
            ['title' => $this->lmsg('settingsTab'), 'action' => 'index'],
            ['title' => $this->lmsg('domainsTab'), 'action' => 'domains'],
        ];
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
                $redirect = $this->runOperation($operation, $domain) ?? $redirect;
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
            $report = Modules_DondominioDns_Runner::importer()->importAll();
            Modules_DondominioDns_Config::setReport($report);
            $this->_status->addInfo($this->lmsg('importAllDone', ['imported' => count($report['imported']), 'errors' => count($report['errors'])]));
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
        $plan = Modules_DondominioDns_Runner::engine()->plan($domain, Modules_DondominioDns_Runner::desired($domain));
        $rows = [];
        foreach (['create' => $plan['create'], 'delete' => $plan['delete']] as $op => $recs) {
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
                Modules_DondominioDns_Runner::importer()->importOne($domain);
                $this->_status->addInfo($this->lmsg('imported', ['domain' => $domain]));
                return null;
            case 'enable':
                Modules_DondominioDns_Runner::syncDomain($domain);
                Modules_DondominioDns_Config::setEnabled(array_merge(Modules_DondominioDns_Config::enabled(), [$domain]));
                $this->_status->addInfo($this->lmsg('enabled', ['domain' => $domain]));
                return null;
            case 'disable':
                Modules_DondominioDns_Config::setEnabled(array_diff(Modules_DondominioDns_Config::enabled(), [$domain]));
                Modules_DondominioDns_Runner::forget($domain);
                $this->_status->addInfo($this->lmsg('disabled', ['domain' => $domain]));
                return null;
            case 'force':
                if (!in_array($domain, Modules_DondominioDns_Config::enabled(), true)) {
                    throw new pm_Exception($this->lmsg('notEnabled', ['domain' => $domain]));
                }
                Modules_DondominioDns_Runner::syncDomain($domain, true);
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
        $rows = [];
        foreach ($domains as $d) {
            $f = $failures[$d] ?? null;
            $status = !in_array($d, $enabled, true) ? 'statusOff' : ($f === null ? 'statusOn' : (!empty($f['blocked']) ? 'statusBlocked' : 'statusFailing'));
            $rows[] = ['domain' => $d, 'status' => $this->lmsg($status), 'error' => $f['error'] ?? ''];
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
