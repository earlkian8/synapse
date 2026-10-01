import { BookOpen } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import type { HelpCategory } from '../types';
import { HelpNav } from './help-nav';

/**
 * The topics sidebar on a narrow screen, where there is no room beside the
 * article: a button that opens it as a panel, closing again once a link is
 * followed.
 */
export function HelpNavSheet(props: {
    categories: HelpCategory[];
    currentCategory?: string;
    currentArticle?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button variant="outline" size="sm" className="lg:hidden">
                    <BookOpen className="size-4" />
                    Browse topics
                </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-80 overflow-y-auto p-4">
                <SheetHeader className="p-0 pb-3">
                    <SheetTitle>Help topics</SheetTitle>
                    <SheetDescription className="sr-only">
                        Every topic and article you can read.
                    </SheetDescription>
                </SheetHeader>
                <HelpNav {...props} onNavigate={() => setOpen(false)} />
            </SheetContent>
        </Sheet>
    );
}
