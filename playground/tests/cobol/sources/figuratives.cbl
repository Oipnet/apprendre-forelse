       IDENTIFICATION DIVISION.
       PROGRAM-ID. T.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01  VC.
           05  FT.
               10  AA-FT PIC X VALUE '0'.
               10  BB-FT PIC X VALUE '1'.
           05  IND.
               10  IND1 PIC X(3).
       01  XIND PIC X(3) VALUE 'ABC'.
       PROCEDURE DIVISION.
       F01.
           MOVE HIGH-VALUE TO IND.
           IF XIND < IND NEXT SENTENCE ELSE GO TO F02.
           DISPLAY 'INF'.
       F02.
           IF FT = ALL '1' DISPLAY 'FINI' ELSE DISPLAY 'PAS FINI'.
           MOVE '1' TO AA-FT.
           IF FT = ALL '1' DISPLAY 'FINI'.
           STOP RUN.
