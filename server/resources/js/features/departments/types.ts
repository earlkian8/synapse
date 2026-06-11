export type DepartmentHead = {
    id: number;
    full_name: string;
    employee_no: string;
    initials: string;
};

export type Position = {
    id: number;
    title: string;
    department_id: number | null;
    salary_grade_min: string | null;
    salary_grade_max: string | null;
    description: string | null;
    employees_count?: number;
    created_human: string | null;
};

export type Department = {
    id: number;
    hashid: string;
    name: string;
    code: string;
    description: string | null;
    parent_id: number | null;
    head_id: number | null;
    is_archived: boolean;
    head: DepartmentHead | null;
    parent?: { id: number; name: string } | null;
    employees_count?: number;
    positions_count?: number;
    children_count?: number;
    positions?: Position[];
    created_human: string | null;
    deleted_at: string | null;
};

export type DepartmentStats = {
    departments: number;
    positions: number;
    employees: number;
    unheaded: number;
};

export type EmployeeOption = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type DepartmentPermissions = {
    manage: boolean;
};

export type DepartmentsPageProps = {
    departments: Department[];
    archived: Department[];
    stats: DepartmentStats;
    options: { employees: EmployeeOption[] };
    can: DepartmentPermissions;
};
