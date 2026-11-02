<?php
pm_Loader::registerAutoload();
pm_Context::init('dondominio-dns');

pm_ApiCli::call('server_dns', ['--disable-custom-backend'], pm_ApiCli::RESULT_FULL);
pm_Scheduler::getInstance()->removeAllTasks();
exit(0);
