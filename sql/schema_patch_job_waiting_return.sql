-- Schema patch: Add Waiting for Return status to jobs table
ALTER TABLE jobs
    MODIFY COLUMN status ENUM(
        'Draft',
        'Submitted',
        'Approved',
        'Planned',
        'Dispatched',
        'In Progress',
        'Waiting for Return',
        'Returned',
        'WH Received',
        'POS Checked',
        'Accounting Ready',
        'Invoiced',
        'Paid',
        'Partial Paid',
        'Closed',
        'Voided'
    ) NOT NULL DEFAULT 'Draft';
