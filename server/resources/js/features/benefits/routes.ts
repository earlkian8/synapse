/**
 * Endpoint map for the Benefits module.
 * Mirrors the named routes in routes/benefits.php. Plans are addressed by hashid;
 * enrollments by numeric id.
 */
export const benefitsRoutes = {
    index: '/benefits',
    show: (hashid: string) => `/benefits/${hashid}`,
    enroll: (hashid: string) => `/benefits/${hashid}/enrollments`,
    enrollment: (id: number) => `/benefits/enrollments/${id}`,
} as const;
