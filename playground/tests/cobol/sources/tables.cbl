       IDENTIFICATION DIVISION.
       PROGRAM-ID. D.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01  CAT-TAB.
           05  CAT PIC XX OCCURS 5.
       01  ST-TA.
           05  ST-ABS PIC X(4) VALUE 'ABS '.
           05  ST-TT OCCURS 3 TIMES.
               10  ST-ST PIC XX VALUE '..'.
               10  ST-LI PIC 99.
       01  TS-01 VALUE '010203040506'.
           05  FILLER PIC X(12).
       01  TS-01R REDEFINES TS-01.
           05  TS-E OCCURS 3.
               10  TS-A PIC 99.
               10  TS-B PIC 99.
       01  W.
           05  W-NUM PIC 9(4).
           05  W-TXT REDEFINES W-NUM PIC X(4).
           05  W-SUITE PIC X(2) VALUE 'ZZ'.
       01  J00 PIC S9(4) COMP VALUE +1.
       01  J01 PIC S9(4) COMPUTATIONAL-3 VALUE ZERO.
       01  ST9 PIC 9 VALUE 2.
       01  T2.
           05  L1 OCCURS 2.
               10  L2 OCCURS 3.
                   15  V PIC 9.
       01  TI.
           05  TIE PIC X OCCURS 4 INDEXED BY IX.
       01  BZ PIC ZZ9 BLANK WHEN ZERO.
       PROCEDURE DIVISION.
       F01.
           MOVE 'AA' TO CAT (1). MOVE 'BB' TO CAT (J00 + 1).
           ADD 2 TO J00.
           MOVE 'CC', TO CAT (J00).
           DISPLAY CAT-TAB '|'.
           DISPLAY ST-TA '|'.
           MOVE 7 TO ST-LI (2).
           MOVE 'XY' TO ST-ST (3).
           DISPLAY ST-TA '|' ST-TT (2) '|'.
           DISPLAY TS-A (2) TS-B (3).
           MOVE 42 TO W-NUM. DISPLAY W-TXT W-SUITE.
           MOVE 1 TO J01.
       F02.
           ADD 1 TO J01.
           IF J01 > 4 GO TO F05.
           GO TO F03 F04 DEPENDING ON J01.
           DISPLAY 'HORS ' J01.
           GO TO F02.
       F03. DISPLAY 'F03'. GO TO F02.
       F04. DISPLAY 'F04'. GO TO F02.
       F05.
           MOVE 1 TO J00.
       F05-B.
           MOVE J00 TO V (2, J00).
           MOVE 5 TO V (1 J00).
           ADD 1 TO J00.
           IF J00 NOT > 3 GO TO F05-B.
           DISPLAY T2.
           SET IX TO 1.
       F06.
           MOVE 'Q' TO TIE (IX).
           SET IX UP BY 2.
           IF IX < 5 GO TO F06.
           DISPLAY TI '|'.
           INITIALIZE ST-TT (1).
           DISPLAY ST-TA '|'.
           INITIALIZE ST-TA.
           DISPLAY ST-TA '|'.
           MOVE 0 TO BZ. DISPLAY '[' BZ ']'.
           MOVE 5 TO BZ. DISPLAY '[' BZ ']'.
           MOVE ALL '*-' TO W-TXT. DISPLAY W.
           IF CAT (3) = SPACES DISPLAY 'VIDE' ELSE DISPLAY CAT (3).
           MOVE LOW-VALUE TO CAT (4).
           IF CAT (4) = LOW-VALUES DISPLAY 'BAS'.
           STOP RUN.
