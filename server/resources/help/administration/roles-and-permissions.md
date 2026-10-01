A **role** is a set of **permissions**, and a permission is one thing a person may do — _View leave requests & balances_, _Approve / reject leave & set balances_, _Clock in / out_. Everything in SYNAPSE checks them: the sidebar, every page and button, the dashboard, the assistant, and this Help Center. Manage roles under **System** → **Roles & Permissions**.

## The built-in roles

Every company starts with three:

| Role                | Who it's for                  | What it can do                                                                                                                                                                        |
| ------------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **HR Manager**      | The company owner and HR team | Everything. It can't be edited, because it holds every permission.                                                                                                                    |
| **Department Head** | Supervisors                   | Clock in and file their own leave; see employee records, attendance, onboarding, offboarding, training, awards and events; approve leave; run appraisals; read the three predictions. |
| **Staff**           | Every employee                | Clock in and out, and file and cancel their own leave.                                                                                                                                |

Built-in roles can't be deleted. Everyone who joins your company starts as **Staff**.

## Creating a role

1. Choose **New role**, and give it a name and a description.
2. Tick its permissions in the matrix. They're grouped by module, each group with a select-all, and a live count shows how many you've chosen.
3. Choose **Create role**.

Then give the role to people from [User Management](/help/administration/user-accounts).

> [!TIP]
> Give each person the smallest role that lets them do their job, and build roles around jobs — _Recruiter_, _Payroll Officer_, _Site Supervisor_ — rather than around people.

## You can only give what you hold

To stop anyone granting themselves more access through a role:

- you can add a permission to a role only if you hold it yourself;
- you can give someone a role only if you hold everything it grants;
- only an HR Manager can make someone an HR Manager, or take it away — and the last active HR Manager can't lose it.

Permissions you can't add show as unavailable in the matrix.

## Reviewing and tidying up

Open a role to see every permission it grants or doesn't, how many people hold it, and how many permissions it has. Search and filter by built-in or custom roles. Tick custom roles to delete several at once; built-in roles are skipped. **Export** downloads the list.

Every change to a role is recorded in the [activity logs](/help/administration/activity-logs), with the permissions it gained and lost.
