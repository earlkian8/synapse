export type WeekDay = 'Mon' | 'Tue' | 'Wed' | 'Thu' | 'Fri' | 'Sat' | 'Sun';

export type WorkSchedule = {
    id: number;
    hashid: string;
    name: string;
    start_time: string | null; // "HH:MM"
    end_time: string | null; // "HH:MM"
    work_days: WeekDay[];
    grace_minutes: number;
    required_hours: number;
    employees_count: number;
};

export type HolidayType = 'regular' | 'special_non_working' | 'special_working';

export type Holiday = {
    id: number;
    hashid: string;
    name: string;
    date: string | null; // "Y-m-d"
    type: HolidayType;
    is_recurring: boolean;
    month: number | null;
    day: number | null;
};

export type SchedulePermissions = {
    manage: boolean;
};

export type ScheduleSetupPageProps = {
    schedules: WorkSchedule[];
    archivedSchedules: WorkSchedule[];
    holidays: Holiday[];
    archivedHolidays: Holiday[];
    can: SchedulePermissions;
};
